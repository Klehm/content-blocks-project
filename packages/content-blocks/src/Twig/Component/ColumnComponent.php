<?php

declare(strict_types=1);

namespace ContentBlocks\Twig\Component;

use ContentBlocks\BlockType\BlockTypeRegistry;
use ContentBlocks\Entity\Block;
use ContentBlocks\Entity\Column;
use ContentBlocks\Security\AccessCheckerInterface;
use ContentBlocks\Security\ContentBlocksAccessDeniedException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveArg;
use Symfony\UX\LiveComponent\Attribute\LiveListener;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;

#[AsLiveComponent('ContentBlocks:Column', template: '@ContentBlocks/components/Column.html.twig')]
final class ColumnComponent
{
    use DefaultActionTrait;

    #[LiveProp]
    public int $columnId;

    #[LiveProp]
    public ?int $lastAddedBlockId = null;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly BlockTypeRegistry $blockTypeRegistry,
        private readonly AccessCheckerInterface $accessChecker,
    ) {
    }

    public function getColumn(): Column
    {
        return $this->em->find(Column::class, $this->columnId);
    }

    public function getBlockTypes(): array
    {
        return $this->blockTypeRegistry->getChoices();
    }

    #[LiveAction]
    public function addBlock(#[LiveArg('type')] string $type): void
    {
        $this->denyUnlessCanEdit();

        if (!$this->blockTypeRegistry->has($type)) {
            throw new \InvalidArgumentException(sprintf('Unknown block type "%s".', $type));
        }

        $column = $this->getColumn();
        $blockType = $this->blockTypeRegistry->get($type);

        $block = new Block();
        $block->setType($type);
        $block->setDraftData($blockType->getDefaultData());
        $block->setPreviewPosition($column->getBlocks()->count());

        $column->addBlock($block);
        $this->em->flush();

        $this->lastAddedBlockId = $block->getId();
    }

    #[LiveListener('block:deleted')]
    public function onBlockDeleted(#[LiveArg] int $blockId): void
    {
        $block = $this->em->find(Block::class, $blockId);
        if ($block) {
            $contentArea = $block->getColumn()->getSection()->getContentArea();
            if (!$this->accessChecker->canEdit($contentArea)) {
                throw new ContentBlocksAccessDeniedException();
            }

            $this->em->remove($block);
            $this->em->flush();
        }
    }

    #[LiveAction]
    public function removeBlock(#[LiveArg('id')] int $id): void
    {
        $block = $this->em->find(Block::class, $id);
        if ($block) {
            $contentArea = $block->getColumn()->getSection()->getContentArea();
            if (!$this->accessChecker->canEdit($contentArea)) {
                throw new ContentBlocksAccessDeniedException();
            }

            $this->em->remove($block);
            $this->em->flush();
        }
    }

    private function denyUnlessCanEdit(): void
    {
        $contentArea = $this->getColumn()->getSection()->getContentArea();
        if (!$this->accessChecker->canEdit($contentArea)) {
            throw new ContentBlocksAccessDeniedException();
        }
    }
}
