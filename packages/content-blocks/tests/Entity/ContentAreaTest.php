<?php

declare(strict_types=1);

namespace ContentBlocks\Tests\Entity;

use ContentBlocks\Entity\Block;
use ContentBlocks\Entity\Column;
use ContentBlocks\Entity\ContentArea;
use ContentBlocks\Entity\Section;
use PHPUnit\Framework\TestCase;

final class ContentAreaTest extends TestCase
{
    public function testEmptyContentAreaHasNoUnpublishedChanges(): void
    {
        $area = new ContentArea();

        $this->assertFalse($area->hasUnpublishedChanges());
    }

    public function testSectionLevelChangePropagatesToContentArea(): void
    {
        $area = $this->makeAreaWithFullyPublishedTree();

        // Mark first section as deleted (draft state).
        $area->getSections()->first()->setDeleted(true);

        $this->assertTrue($area->hasUnpublishedChanges());
    }

    public function testColumnLevelChangePropagatesToContentArea(): void
    {
        $area = $this->makeAreaWithFullyPublishedTree();

        $section = $area->getSections()->first();
        $section->getColumns()->first()->setPreviewPosition(99);

        $this->assertTrue($area->hasUnpublishedChanges());
    }

    public function testBlockLevelChangePropagatesToContentArea(): void
    {
        $area = $this->makeAreaWithFullyPublishedTree();

        $section = $area->getSections()->first();
        $column = $section->getColumns()->first();
        $column->getBlocks()->first()->setDraftData(['title' => 'Edited']);

        $this->assertTrue($area->hasUnpublishedChanges());
    }

    public function testFullyPublishedTreeReportsClean(): void
    {
        $area = $this->makeAreaWithFullyPublishedTree();

        $this->assertFalse($area->hasUnpublishedChanges());
    }

    /**
     * Builds an area with one section, one column, one block — all in a fully
     * published, no-pending-changes state.
     */
    private function makeAreaWithFullyPublishedTree(): ContentArea
    {
        $area = new ContentArea();

        $section = new Section();
        $section->setPosition(0);
        $section->setPreviewPosition(0);
        $section->publish();
        $area->addSection($section);

        $column = new Column();
        $column->setPosition(0);
        $column->setPreviewPosition(0);
        $column->publish();
        $section->addColumn($column);

        $block = new Block();
        $block->setType('text');
        $block->setPublishedData(['title' => 'Hello']);
        $block->setPosition(0);
        $block->setPreviewPosition(0);
        $column->addBlock($block);

        return $area;
    }
}
