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
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
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

    private function denyUnlessCanEdit(): void
    {
        $contentArea = $this->getBlock()->getColumn()->getSection()->getContentArea();
        if (!$this->accessChecker->canEdit($contentArea)) {
            throw new ContentBlocksAccessDeniedException();
        }
    }
}
