<?php

declare(strict_types=1);

namespace ContentBlocks\Tests\Entity;

use ContentBlocks\Entity\Column;
use PHPUnit\Framework\TestCase;

final class ColumnTest extends TestCase
{
    public function testFreshColumnHasNoUnpublishedChangesByDefault(): void
    {
        $column = new Column();

        $this->assertFalse($column->hasUnpublishedChanges());
    }

    public function testPreviewPositionDivergesMarksUnpublishedChanges(): void
    {
        $column = new Column();
        $column->setPosition(0);
        $column->setPreviewPosition(1);

        $this->assertTrue($column->hasUnpublishedChanges());
    }

    public function testDeletedMarksUnpublishedChanges(): void
    {
        $column = new Column();
        $column->setDeleted(true);

        $this->assertTrue($column->hasUnpublishedChanges());
    }

    public function testPublishSyncsPosition(): void
    {
        $column = new Column();
        $column->setPosition(0);
        $column->setPreviewPosition(2);

        $column->publish();

        $this->assertSame(2, $column->getPosition());
        $this->assertFalse($column->hasUnpublishedChanges());
    }

    public function testRevertDraftClearsPendingState(): void
    {
        $column = new Column();
        $column->setPosition(1);
        $column->setPreviewPosition(3);
        $column->setDeleted(true);

        $column->revertDraft();

        $this->assertSame(1, $column->getPreviewPosition());
        $this->assertFalse($column->isDeleted());
        $this->assertFalse($column->hasUnpublishedChanges());
    }
}
