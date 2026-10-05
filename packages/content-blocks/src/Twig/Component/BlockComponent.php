<?php

declare(strict_types=1);

namespace ContentBlocks\Twig\Component;

use ContentBlocks\Block\CollectionItemIds;
use ContentBlocks\BlockType\BlockTypeInterface;
use ContentBlocks\BlockType\BlockTypeRegistry;
use ContentBlocks\Entity\Block;
use ContentBlocks\Event\AfterBlockSaveEvent;
use ContentBlocks\Event\BeforeBlockSaveEvent;
use ContentBlocks\Form\Type\BlockFormType;
use ContentBlocks\History\ActionJournal;
use ContentBlocks\History\JournalScope;
use ContentBlocks\Security\AccessCheckerInterface;
use ContentBlocks\Security\ContentBlocksAccessDeniedException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\PropertyAccess\PropertyAccessorInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveArg;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\Attribute\PostHydrate;
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

    /**
     * Why a listener refused the last save; not a LiveProp, so the next
     * request starts without it.
     *
     * @var list<string>
     */
    public array $refusal = [];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly BlockTypeRegistry $blockTypeRegistry,
        private readonly FormFactoryInterface $formFactory,
        private readonly AccessCheckerInterface $accessChecker,
        private readonly \ContentBlocks\Block\BlockDataDefaults $blockDataDefaults,
        private readonly CollectionItemIds $collectionItemIds,
        private readonly ActionJournal $journal,
        private readonly ?EventDispatcherInterface $events = null,
        private readonly bool $blockStyling = true,
    ) {
    }

    /**
     * Every request after the mount carries replayable props: a re-render or
     * a collection add/remove shows the draft, so it is authorized too.
     */
    #[PostHydrate]
    public function authorizeRequest(): void
    {
        $this->denyUnlessCanEdit();
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
        $label = $blockType->getLabel();

        return match (true) {
            is_string($label) => $label,
            $label instanceof \Stringable => (string) $label,
            default => $this->getBlock()->getType(),
        };
    }

    protected function instantiateForm(): FormInterface
    {
        $blockType = $this->getBlockType();
        $initial = $this->initialData();

        return $this->formFactory->create(
            BlockFormType::class,
            $initial,
            [
                'block_type' => $blockType,
                'block_data' => $initial,
                'include_styling' => $this->blockStyling,
            ]
        );
    }

    /**
     * The stored draft with the defaults filling its holes: what the form
     * starts from, and what an invalid field falls back to on save.
     *
     * @return array<string, mixed>
     */
    private function initialData(): array
    {
        $block = $this->getBlock();
        $data = $block->getDraftData() ?? $block->getPublishedData() ?? [];

        // Recursive, so it only fills holes. See
        // forms.md#why-defaults-are-merged-on-form-load
        return array_replace_recursive($this->blockDataDefaults->get(), $data);
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

        $data = $this->validData();
        if ($data === null) {
            return;
        }

        $block = $this->getBlock();
        $area = $block->getColumn()?->getSection()?->getContentArea();
        // The one place that can guarantee every collection entry carries its
        // stable id, including ones just added or duplicated.
        $data = $this->collectionItemIds->backfill($this->getForm(), $data);

        if ($area !== null && $this->events !== null) {
            $before = $this->events->dispatch(new BeforeBlockSaveEvent($block, $area, $data));
            if ($before->isRefused()) {
                $this->refusal = $before->getReasons();
                $this->dispatchBrowserEvent('cb:block:refused', ['blockId' => $this->blockId]);

                return;
            }
        }

        $write = function () use ($block, $data): void {
            $block->setDraftData($data);
            $this->em->flush();
        };

        // Coalesced per block: autosave fires per keystroke burst, and one
        // paragraph of typing must not become forty undo steps.
        if ($area === null) {
            $write();
        } else {
            $this->journal->record(
                $area,
                'block.data',
                JournalScope::blockData($block),
                $write,
                'block.data:' . $this->blockId,
            );
            $this->events?->dispatch(new AfterBlockSaveEvent($block, $area));
        }

        $this->dispatchBrowserEvent('cb:block:saved', ['blockId' => $this->blockId]);
    }

    /**
     * The submitted data, an invalid field kept at its stored value; null when
     * nothing valid changed. Errors show on the fields the editor touched.
     *
     * @see docs/internals/forms.md#an-invalid-field-does-not-hold-the-others
     *
     * @return array<string, mixed>|null
     */
    private function validData(): ?array
    {
        // Validating everything resets the list of touched fields.
        $touched = $this->validatedFields;
        try {
            $this->submitForm(true);
            $valid = true;
        } catch (UnprocessableEntityHttpException) {
            $valid = false;
        }
        $this->isValidated = false;
        $this->validatedFields = $touched;

        $form = $this->getForm();
        $data = $form->getData();
        $data = \is_array($data) ? $data : [];
        if ($valid) {
            return $data;
        }

        $initial = $this->initialData();
        foreach ($form as $name => $child) {
            if ($child->isValid()) {
                continue;
            }
            if (\array_key_exists($name, $initial)) {
                $data[$name] = $initial[$name];
            } else {
                unset($data[$name]);
            }
        }
        // Own errors (a constraint across fields) hold the whole save.
        $whole = \count($form->getErrors()) === 0;

        foreach ($form as $name => $child) {
            $this->clearErrorsForNonValidatedFields($child, $form->getName() . '.' . $name);
        }
        // Built before the errors were cleared: build it again.
        $this->formView = null;

        return $whole && $data != $initial ? $data : null;
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
