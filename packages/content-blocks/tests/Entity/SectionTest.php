<?php

declare(strict_types=1);

namespace ContentBlocks\Tests\Entity;

use ContentBlocks\Entity\Section;
use PHPUnit\Framework\TestCase;

final class SectionTest extends TestCase
{
    public function testFreshSectionHasUnpublishedChanges(): void
    {
        // A section just instantiated is, by definition, not yet published —
        // publishedAt is null until publish() runs.
        $section = new Section();

        $this->assertTrue($section->hasUnpublishedChanges());
        $this->assertFalse($section->isPublished());
    }

    public function testPublishedSectionWithoutDraftIsClean(): void
    {
        $section = new Section();
        $section->publish();

        $this->assertFalse($section->hasUnpublishedChanges());
        $this->assertTrue($section->isPublished());
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

    public function testPublishSyncsPositionAndStampsPublishedAt(): void
    {
        $section = new Section();
        $section->setPosition(1);
        $section->setPreviewPosition(4);

        $section->publish();

        $this->assertSame(4, $section->getPosition());
        $this->assertNotNull($section->getPublishedAt());
        $this->assertFalse($section->hasUnpublishedChanges());
    }

    public function testRevertDraftClearsPendingStateOnPublishedSection(): void
    {
        $section = new Section();
        $section->setPosition(2);
        $section->setPreviewPosition(2);
        $section->publish();
        // Now mutate the draft state.
        $section->setPreviewPosition(7);
        $section->setDeleted(true);

        $section->revertDraft();

        $this->assertSame(2, $section->getPreviewPosition());
        $this->assertFalse($section->isDeleted());
        $this->assertFalse($section->hasUnpublishedChanges());
    }
}
