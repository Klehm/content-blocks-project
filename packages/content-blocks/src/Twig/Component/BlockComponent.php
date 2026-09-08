<?php

declare(strict_types=1);

namespace ContentBlocks\Twig\Component;

use ContentBlocks\Block\CollectionItemIds;
use ContentBlocks\BlockType\BlockTypeInterface;
use ContentBlocks\BlockType\BlockTypeRegistry;
use ContentBlocks\Entity\Block;
use ContentBlocks\Form\Type\BlockFormType;
use ContentBlocks\Security\AccessCheckerInterface;
use ContentBlocks\Security\ContentBlocksAccessDeniedException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\PropertyAccess\PropertyAccessorInterface;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveArg;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\ComponentToolsTrait;
use Symfony\UX\LiveComponent\DefaultActionTrait;
use Symfony\UX\LiveComponent\LiveCollectionTrait;

/**
 * Live Component editing one Block in the builder sidebar. Save and cancel
 * dispatch a bubbling CustomEvent that `cb-builder` acts on.
 *
 * @see docs/internals/forms.md#the-block-form-is-the-whitelist
 *
 * @internal driven by the builder templates, not a host extension point
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
        private readonly CollectionItemIds $collectionItemIds,
    ) {
    }

    public function getBlock(): Block
    {
        return $this->em->find(Block::class, $this->blockId)
            ?? throw new NotFoundHttpException(sprintf('Block %s no longer exists.', $this->blockId));
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
        if ($blockType === null) {
            return $this->getBlock()->getType();
        }

        // getLabel() may return a TranslatableInterface, which is not itself
        // castable — templates localize the result with |trans.
        $label = $blockType::getLabel();

        return match (true) {
            is_string($label) => $label,
            $label instanceof \Stringable => (string) $label,
            default => $this->getBlock()->getType(),
        };
    }

    protected function instantiateForm(): FormInterface
    {
        $block = $this->getBlock();
        $blockType = $this->getBlockType();
        $data = $block->getDraftData() ?? $block->getPublishedData() ?? [];

        // Recursive, so it only fills holes. See
        // forms.md#why-defaults-are-merged-on-form-load
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
     * Moves a collection entry from one 0-based DOM position to another, and
     * persists the draft itself because autosave cannot see the change.
     *
     * @see docs/internals/forms.md#why-collection-reorder-and-duplicate-flush
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
     * Inserts a copy right after the entry at 0-based position $index, and
     * persists the draft itself for the same reason as the reorder above.
     *
     * @see docs/internals/forms.md#why-collection-reorder-and-duplicate-flush
     */
    #[LiveAction]
    public function duplicateCollectionItem(
        PropertyAccessorInterface $propertyAccessor,
        #[LiveArg] string $name,
        #[LiveArg] int $index,
    ): void {
        $this->denyUnlessCanEdit();

        $propertyPath = $this->collectionPropertyPath($name);
        $data = $propertyAccessor->getValue($this->formValues, $propertyPath);
        if (!\is_array($data)) {
            return;
        }

        $duplicated = self::duplicateInCollection($data, $index);
        if (null === $duplicated) {
            return;
        }

        $propertyAccessor->setValue($this->formValues, $propertyPath, $duplicated);

        $this->persistDraft();
    }

    /**
     * The single funnel for every draft write. A no-op when the block type is
     * gone or the form fails validation, which re-renders with errors.
     *
     * @see docs/internals/forms.md#the-block-form-is-the-whitelist
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
        // The one place that can guarantee every collection entry carries its
        // stable id, including ones just added or duplicated.
        $form = $this->getForm();
        $block->setDraftData($this->collectionItemIds->backfill($form, $form->getData()));
        $this->em->flush();

        $this->dispatchBrowserEvent('cb:block:saved', ['blockId' => $this->blockId]);
    }

    /**
     * Null when the move is a no-op or out of range, so the caller can skip
     * the write. Keys are normalized to a contiguous 0..n list.
     *
     * @see docs/internals/forms.md#why-collection-reorder-and-duplicate-flush
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
     * Null when $index is out of range, so the caller can skip the write.
     * Keys are normalized to a contiguous 0..n list.
     *
     * @see docs/internals/forms.md#why-collection-reorder-and-duplicate-flush
     *
     * @param array<int|string, mixed> $data
     *
     * @return list<mixed>|null
     */
    private static function duplicateInCollection(array $data, int $index): ?array
    {
        $values = array_values($data);
        $count = \count($values);
        if ($index < 0 || $index >= $count) {
            return null;
        }

        $copy = $values[$index];
        // A copy is a new entry: sharing the source's id would make anything
        // keyed per entry address both at once. persistDraft() mints a fresh.
        if (\is_array($copy)) {
            unset($copy[CollectionItemIds::KEY]);
        }

        array_splice($values, $index + 1, 0, [$copy]);

        return $values;
    }

    /**
     * `content_block[tabs]` to a PropertyAccessor path. Mirrors
     * LiveCollectionTrait::fieldNameToPropertyPath, private to the trait.
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
        // A broken column/section/area chain leaves nothing to authorize
        // against, so the guard denies rather than crashing on a null.
        $contentArea = $this->getBlock()->getColumn()?->getSection()?->getContentArea();
        if ($contentArea === null || !$this->accessChecker->canEdit($contentArea)) {
            throw new ContentBlocksAccessDeniedException();
        }
    }
}
