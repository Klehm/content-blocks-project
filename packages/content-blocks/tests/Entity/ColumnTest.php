<?php

declare(strict_types=1);

namespace ContentBlocks\Tests\Entity;

use ContentBlocks\Entity\Column;
use PHPUnit\Framework\TestCase;

final class ColumnTest extends TestCase
{
    public function testFreshColumnHasUnpublishedChanges(): void
    {
        $column = new Column();

        $this->assertTrue($column->hasUnpublishedChanges());
        $this->assertFalse($column->isPublished());
    }

    public function testPublishedColumnWithoutDraftIsClean(): void
    {
        $column = new Column();
        $column->publish();

        $this->assertFalse($column->hasUnpublishedChanges());
        $this->assertTrue($column->isPublished());
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

    public function testPublishSyncsPositionAndStampsPublishedAt(): void
    {
        $column = new Column();
        $column->setPosition(0);
        $column->setPreviewPosition(2);

        $column->publish();

        $this->assertSame(2, $column->getPosition());
        $this->assertNotNull($column->getPublishedAt());
        $this->assertFalse($column->hasUnpublishedChanges());
    }

    public function testRevertDraftClearsPendingStateOnPublishedColumn(): void
    {
        $column = new Column();
        $column->setPosition(1);
        $column->setPreviewPosition(1);
        $column->publish();
        // Mutate draft state.
        $column->setPreviewPosition(3);
        $column->setDeleted(true);

        $column->revertDraft();

        $this->assertSame(1, $column->getPreviewPosition());
        $this->assertFalse($column->isDeleted());
        $this->assertFalse($column->hasUnpublishedChanges());
    }
}
