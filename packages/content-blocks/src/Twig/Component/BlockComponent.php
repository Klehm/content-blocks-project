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
use Symfony\UX\LiveComponent\Attribute\PreReRender;
use Symfony\UX\LiveComponent\ComponentToolsTrait;
use Symfony\UX\LiveComponent\DefaultActionTrait;
use Symfony\UX\LiveComponent\LiveCollectionTrait;
use Symfony\UX\TwigComponent\Attribute\PostMount;

#[AsLiveComponent('ContentBlocks:Block', template: '@ContentBlocks/components/Block.html.twig')]
final class BlockComponent
{
    use DefaultActionTrait;
    use ComponentToolsTrait;
    use LiveCollectionTrait {
        LiveCollectionTrait::initializeForm as private traitInitializeForm;
        LiveCollectionTrait::submitFormOnRender as private traitSubmitFormOnRender;
    }

    #[LiveProp]
    public int $blockId;

    #[LiveProp(writable: true)]
    public bool $editing = false;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly BlockTypeRegistry $blockTypeRegistry,
        private readonly FormFactoryInterface $formFactory,
        private readonly AccessCheckerInterface $accessChecker,
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

        return $blockType ? $blockType::getLabel() : $this->getBlock()->getType();
    }

    protected function instantiateForm(): FormInterface
    {
        $block = $this->getBlock();
        $blockType = $this->getBlockType();
        $data = $block->getData();

        return $this->formFactory->create(
            BlockFormType::class,
            $data,
            [
                'block_type' => $blockType,
                'block_data' => $data,
            ]
        );
    }

    /**
     * Only initialize form when in editing mode.
     */
    #[PostMount]
    public function initializeForm(array $data): array
    {
        if (!$this->editing) {
            return $data;
        }

        return $this->traitInitializeForm($data);
    }

    /**
     * Only auto-submit form when in editing mode.
     */
    #[PreReRender]
    public function submitFormOnRender(): void
    {
        if (!$this->editing) {
            return;
        }

        $this->traitSubmitFormOnRender();
    }

    #[LiveAction]
    public function openEdit(): void
    {
        $this->denyUnlessCanEdit();
        $this->editing = true;
        $this->resetForm();
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
        $block->setData($this->getForm()->getData());
        $this->em->flush();

        $this->editing = false;
        $this->resetForm();
    }

    #[LiveAction]
    public function cancelEdit(): void
    {
        $this->editing = false;
        $this->resetForm();
    }

    #[LiveAction]
    public function delete(): void
    {
        $this->denyUnlessCanEdit();
        $this->emitUp('block:deleted', ['blockId' => $this->blockId]);
    }

    private function denyUnlessCanEdit(): void
    {
        $contentArea = $this->getBlock()->getColumn()->getSection()->getContentArea();
        if (!$this->accessChecker->canEdit($contentArea)) {
            throw new ContentBlocksAccessDeniedException();
        }
    }
}
