<?php

declare(strict_types=1);

namespace ContentBlocks\I18n\Tests\Entity;

use ContentBlocks\I18n\Entity\BlockTranslation;
use ContentBlocks\I18n\Field\SourceDigest;
use PHPUnit\Framework\TestCase;

/**
 * The draft/published duality, which mirrors {@see \ContentBlocks\Entity\Block}
 * exactly so translations can never be a lifecycle step out of sync with the
 * text they translate.
 */
final class BlockTranslationTest extends TestCase
{
    public function testEffectiveValuesPreferTheDraft(): void
    {
        $row = new BlockTranslation(null, 'fr');
        $row->setPublishedPayload(['a' => 'published'], []);
        $row->setDraftValue('a', 'draft', 'digest');

        $this->assertSame(['a' => 'draft'], $row->getEffectiveValues());
    }

    public function testEffectiveValuesFallBackToPublished(): void
    {
        $row = new BlockTranslation(null, 'fr');
        $row->setPublishedPayload(['a' => 'published'], []);

        $this->assertSame(['a' => 'published'], $row->getEffectiveValues());
    }

    public function testDigestsAlwaysTravelWithTheValuesTheyAnnotate(): void
    {
        // A digest drifting from its value is worse than none: it would report
        // a fresh translation as stale, or hide a genuinely outdated one.
        $row = new BlockTranslation(null, 'fr');
        $row->setPublishedPayload(['a' => 'published'], ['a' => 'old']);

        $this->assertSame(['a' => 'old'], $row->getEffectiveDigests());

        $row->setDraftValue('a', 'draft', 'new');

        $this->assertSame(['a' => 'new'], $row->getEffectiveDigests());
    }

    public function testPublishPromotesTheDraftAndClearsIt(): void
    {
        $row = new BlockTranslation(null, 'fr');
        $row->setDraftValue('a', 'Bonjour', SourceDigest::of('Hello'));

        $row->publish();

        $this->assertSame(['a' => 'Bonjour'], $row->getPublishedValues());
        $this->assertNull($row->getDraftValues());
        $this->assertFalse($row->hasUnpublishedChanges());
    }

    public function testPublishIsANoOpWithoutADraft(): void
    {
        $row = new BlockTranslation(null, 'fr');
        $row->setPublishedPayload(['a' => 'Bonjour'], []);

        $row->publish();

        $this->assertSame(['a' => 'Bonjour'], $row->getPublishedValues());
    }

    public function testRevertDropsThePendingEdit(): void
    {
        $row = new BlockTranslation(null, 'fr');
        $row->setPublishedPayload(['a' => 'Bonjour'], []);
        $row->setDraftValue('a', 'Salut', 'd');

        $row->revertDraft();

        $this->assertSame(['a' => 'Bonjour'], $row->getEffectiveValues());
        $this->assertFalse($row->hasUnpublishedChanges());
    }

    public function testRemovingAFieldDropsItFromTheDraftOnly(): void
    {
        $row = new BlockTranslation(null, 'fr');
        $row->setPublishedPayload(['a' => 'Bonjour', 'b' => 'Salut'], []);
        $row->removeDraftValue('a');

        $this->assertSame(['b' => 'Salut'], $row->getDraftValues());
        $this->assertSame(['a' => 'Bonjour', 'b' => 'Salut'], $row->getPublishedValues());
    }

    public function testAnEmptyRowReportsItselfAsEmpty(): void
    {
        // The signal the publisher uses to delete rather than keep a row that
        // means nothing forever.
        $row = new BlockTranslation(null, 'fr');

        $this->assertTrue($row->isEmpty());

        $row->setDraftValue('a', 'x', 'd');
        $this->assertFalse($row->isEmpty());

        $row->removeDraftValue('a');
        $this->assertTrue($row->isEmpty());
    }
}
