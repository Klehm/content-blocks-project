<?php

declare(strict_types=1);

namespace ContentBlocks\Twig\Component;

use ContentBlocks\BlockType\BlockTypeRegistry;
use ContentBlocks\Entity\Column;
use ContentBlocks\Entity\ContentArea;
use ContentBlocks\Entity\Section;
use ContentBlocks\Security\AccessCheckerInterface;
use ContentBlocks\Security\ContentBlocksAccessDeniedException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveArg;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;

#[AsLiveComponent('ContentBlocks:ContentAreaBuilder', template: '@ContentBlocks/components/ContentAreaBuilder.html.twig')]
final class ContentAreaBuilderComponent
{
    use DefaultActionTrait;

    private const ALLOWED_LAYOUTS = [
        Section::LAYOUT_FULL,
        Section::LAYOUT_TWO_COLS,
        Section::LAYOUT_THREE_COLS,
    ];

    #[LiveProp]
    public int $contentAreaId;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly BlockTypeRegistry $blockTypeRegistry,
        private readonly AccessCheckerInterface $accessChecker,
    ) {
    }

    public function getContentArea(): ContentArea
    {
        return $this->em->find(ContentArea::class, $this->contentAreaId);
    }

    public function getBlockTypes(): array
    {
        return $this->blockTypeRegistry->getChoices();
    }

    #[LiveAction]
    public function addSection(#[LiveArg] string $layout = Section::LAYOUT_FULL): void
    {
        $contentArea = $this->getContentArea();

        if (!$this->accessChecker->canEdit($contentArea)) {
            throw new ContentBlocksAccessDeniedException();
        }

        if (!\in_array($layout, self::ALLOWED_LAYOUTS, true)) {
            throw new \InvalidArgumentException(sprintf('Invalid layout "%s".', $layout));
        }

        $section = new Section();
        $section->setLayout($layout);
        $section->setPosition($contentArea->getSections()->count());

        $colCount = match ($layout) {
            Section::LAYOUT_TWO_COLS => 2,
            Section::LAYOUT_THREE_COLS => 3,
            default => 1,
        };

        $presets = match ($layout) {
            Section::LAYOUT_TWO_COLS => ['col-6', 'col-6'],
            Section::LAYOUT_THREE_COLS => ['col-4', 'col-4', 'col-4'],
            default => ['col-12'],
        };

        for ($i = 0; $i < $colCount; $i++) {
            $column = new Column();
            $column->setPreset($presets[$i]);
            $column->setPosition($i);
            $section->addColumn($column);
        }

        $contentArea->addSection($section);
        $this->em->persist($contentArea);
        $this->em->flush();
    }
}
