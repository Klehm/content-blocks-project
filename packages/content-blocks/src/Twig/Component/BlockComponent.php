<?php

declare(strict_types=1);

namespace ContentBlocks\Twig\Component;

use ContentBlocks\BlockType\BlockTypeInterface;
use ContentBlocks\BlockType\BlockTypeRegistry;
use ContentBlocks\Entity\Block;
use ContentBlocks\Form\Type\BlockFormType;
use ContentBlocks\Security\AccessCheckerInterface;
use ContentBlocks\Security\ContentBlocksAccessDeniedException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\PropertyAccess\PropertyAccessorInterface;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveArg;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\ComponentToolsTrait;
use Symfony\UX\LiveComponent\DefaultActionTrait;
use Symfony\UX\LiveComponent\LiveCollectionTrait;

/**
 * Live Component for editing a single Block. Designed to be mounted in the
 * builder sidebar — always rendered in edit mode (no inline preview/edit
 * toggle). On save / cancel, dispatches a browser CustomEvent that bubbles
 * up to the parent admin window's `cb-builder` Stimulus controller, which is
 * responsible for closing the sidebar and reloading the iframe.
 */
#[AsLiveComponent('ContentBlocks:Block', template: '@ContentBlocks/components/Block.html.twig')]
final class BlockComponent
{
    use DefaultActionTrait;
    use ComponentToolsTrait;
    use LiveCollectionTrait;

    #[LiveProp]
    public int $blockId;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly BlockTypeRegistry $blockTypeRegistry,
        private readonly FormFactoryInterface $formFactory,
        private readonly AccessCheckerInterface $accessChecker,
        private readonly \ContentBlocks\Block\BlockDataDefaults $blockDataDefaults,
    ) {
    }

    public function getBlock(): Block
    {
        return $this->em->find(Block::class, $this->blockId);
    }

    public function getBlockType(): ?BlockTypeInterface
    {
        $block = $this->getBlock();
        if ($this->blockTypeRegistry->has($block->getType())) {
            return $this->blockTypeRegistry->get($block->getType());
        }

        return null;
    }

    public function getBlockTypeLabel(): string
    {
        $blockType = $this->getBlockType();

        // BlockType::getLabel() may now return a TranslatableInterface; cast
        // to string so this method's contract stays unchanged. Templates that
        // need the localized label should pipe the result through `|trans`
        // with the block type's domain, or call `getLabel()` directly.
        return $blockType ? (string) $blockType::getLabel() : $this->getBlock()->getType();
    }

    protected function instantiateForm(): FormInterface
    {
        $block = $this->getBlock();
        $blockType = $this->getBlockType();
        $data = $block->getDraftData() ?? $block->getPublishedData() ?? [];

        // Backfill defaults so widgets without an "empty" state
        // (notably <input type="color">) open with a sane value rather
        // than the browser's black fallback. Recursive merge keeps the
        // existing data untouched and only fills holes.
        $initial = array_replace_recursive($this->blockDataDefaults->get(), $data);

        return $this->formFactory->create(
            BlockFormType::class,
            $initial,
            [
                'block_type' => $blockType,
                'block_data' => $initial,
            ]
        );
    }

    #[LiveAction]
    public function save(): void
    {
        $this->denyUnlessCanEdit();
        $this->persistDraft();
    }

    /**
     * Reorder the items of a LiveCollectionType field by moving the entry at
     * position $from to position $to. Driven by the cb-collection-sort Stimulus
     * controller on drop (or its keyboard up/down fallback).
     *
     * Unlike add/delete — which add/remove a DOM node that the cb-autosave
     * MutationObserver picks up — a reorder re-renders the *same* positional
     * widget ids with swapped values, i.e. an in-place value change with no
     * childList mutation. The observer would never see it, so this action
     * persists the draft itself (same path as save) and dispatches
     * cb:block:saved to reload the preview. $from / $to are 0-based DOM
     * positions, which is why reorderCollection() works on a positional view.
     */
    #[LiveAction]
    public function moveCollectionItem(
        PropertyAccessorInterface $propertyAccessor,
        #[LiveArg] string $name,
        #[LiveArg] int $from,
        #[LiveArg] int $to,
    ): void {
        $this->denyUnlessCanEdit();

        $propertyPath = $this->collectionPropertyPath($name);
        $data = $propertyAccessor->getValue($this->formValues, $propertyPath);
        if (!\is_array($data)) {
            return;
        }

        $reordered = self::reorderCollection($data, $from, $to);
        if (null === $reordered) {
            return;
        }

        $propertyAccessor->setValue($this->formValues, $propertyPath, $reordered);

        $this->persistDraft();
    }

    /**
     * Submit the live form and commit its data to the block's draft, then tell
     * the admin window to reload the preview iframe. Shared by save() and
     * moveCollectionItem(). A no-op (returns early) when the block type is gone
     * or the form fails validation — the form re-renders with errors instead.
     */
    private function persistDraft(): void
    {
        $blockType = $this->getBlockType();
        if (!$blockType) {
            return;
        }

        try {
            $this->submitForm(true);
        } catch (UnprocessableEntityHttpException) {
            // Validation failed — the form will re-render with errors
            return;
        }

        $block = $this->getBlock();
        $block->setDraftData($this->getForm()->getData());
        $this->em->flush();

        $this->dispatchBrowserEvent('cb:block:saved', ['blockId' => $this->blockId]);
    }

    /**
     * Move the item at index $from to index $to within a positional list.
     * Returns null when the move is a no-op or out of range, so the caller can
     * skip the write. Keys are normalized to a contiguous 0..n list — the live
     * collection re-renders positionally, so sparse keys left over from a prior
     * deletion are irrelevant.
     *
     * @param array<int|string, mixed> $data
     *
     * @return list<mixed>|null
     */
    private static function reorderCollection(array $data, int $from, int $to): ?array
    {
        $values = array_values($data);
        $count = \count($values);
        if ($from < 0 || $from >= $count || $to < 0 || $to >= $count || $from === $to) {
            return null;
        }

        $moved = array_splice($values, $from, 1);
        array_splice($values, $to, 0, $moved);

        return $values;
    }

    /**
     * Resolve a live-collection field's full name (as emitted in
     * data-live-name-param, e.g. "content_block[tabs]") to a PropertyAccessor
     * path into $formValues. Mirrors LiveCollectionTrait::fieldNameToPropertyPath,
     * which is private to the trait.
     */
    private function collectionPropertyPath(string $name): string
    {
        $rootFormName = $this->getFormName();

        $path = $name;
        if (str_starts_with($name, $rootFormName)) {
            $path = substr_replace($name, '', 0, mb_strlen($rootFormName));
        }

        if (!str_starts_with($path, '[')) {
            $path = "[$path]";
        }

        return $path;
    }

    private function denyUnlessCanEdit(): void
    {
        $contentArea = $this->getBlock()->getColumn()->getSection()->getContentArea();
        if (!$this->accessChecker->canEdit($contentArea)) {
            throw new ContentBlocksAccessDeniedException();
        }
    }
}
