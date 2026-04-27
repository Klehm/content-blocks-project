<?php

declare(strict_types=1);

namespace ContentBlocks\Tests\Entity;

use ContentBlocks\Entity\Section;
use PHPUnit\Framework\TestCase;

final class SectionTest extends TestCase
{
    public function testFreshSectionHasNoUnpublishedChangesByDefault(): void
    {
        // A section just instantiated has position=0, previewPosition=0, deleted=false.
        // No data tracked here, so it counts as "in sync".
        $section = new Section();

        $this->assertFalse($section->hasUnpublishedChanges());
    }

    public function testPreviewPositionDivergesMarksUnpublishedChanges(): void
    {
        $section = new Section();
        $section->setPosition(1);
        $section->setPreviewPosition(2);

        $this->assertTrue($section->hasUnpublishedChanges());
    }

    public function testDeletedMarksUnpublishedChanges(): void
    {
        $section = new Section();
        $section->setDeleted(true);

        $this->assertTrue($section->hasUnpublishedChanges());
    }

    public function testPublishSyncsPosition(): void
    {
        $section = new Section();
        $section->setPosition(1);
        $section->setPreviewPosition(4);

        $section->publish();

        $this->assertSame(4, $section->getPosition());
        $this->assertFalse($section->hasUnpublishedChanges());
    }

    public function testRevertDraftClearsPendingState(): void
    {
        $section = new Section();
        $section->setPosition(2);
        $section->setPreviewPosition(7);
        $section->setDeleted(true);

        $section->revertDraft();

        $this->assertSame(2, $section->getPreviewPosition());
        $this->assertFalse($section->isDeleted());
        $this->assertFalse($section->hasUnpublishedChanges());
    }
}
