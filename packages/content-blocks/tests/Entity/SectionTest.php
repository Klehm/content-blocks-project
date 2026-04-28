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

    public function testDraftSettingsMarkSectionDirty(): void
    {
        $section = new Section();
        $section->publish();
        $this->assertFalse($section->hasUnpublishedChanges());

        $section->setDraftSettings(['classes' => 'my-custom']);

        $this->assertTrue($section->hasUnpublishedChanges());
    }

    public function testPublishCopiesDraftSettingsToPublishedSettings(): void
    {
        $section = new Section();
        $section->setPublishedSettings(['classes' => 'old']);
        $section->setDraftSettings(['classes' => 'new', 'maxWidth' => 1100]);
        $section->publish();

        $this->assertSame(['classes' => 'new', 'maxWidth' => 1100], $section->getPublishedSettings());
        $this->assertNull($section->getDraftSettings());
    }

    public function testRevertDraftClearsDraftSettings(): void
    {
        $section = new Section();
        $section->setPublishedSettings(['classes' => 'stable']);
        $section->publish();
        $section->setDraftSettings(['classes' => 'pending']);

        $section->revertDraft();

        $this->assertNull($section->getDraftSettings());
        $this->assertSame(['classes' => 'stable'], $section->getPublishedSettings());
    }

    public function testGetEffectiveSettingsPrefersDraftWhenRequested(): void
    {
        $section = new Section();
        $section->setPublishedSettings(['classes' => 'public']);
        $section->setDraftSettings(['classes' => 'draft']);

        $this->assertSame(['classes' => 'public'], $section->getEffectiveSettings(preferDraft: false));
        $this->assertSame(['classes' => 'draft'], $section->getEffectiveSettings(preferDraft: true));
    }

    public function testGetEffectiveSettingsFallsBackToPublishedWhenDraftAbsent(): void
    {
        $section = new Section();
        $section->setPublishedSettings(['classes' => 'stable']);

        $this->assertSame(['classes' => 'stable'], $section->getEffectiveSettings(preferDraft: true));
    }
}
