<?php

declare(strict_types=1);

namespace ContentBlocks\Tests\Entity;

use ContentBlocks\Entity\Block;
use PHPUnit\Framework\TestCase;

final class BlockTest extends TestCase
{
    public function testFreshBlockHasUnpublishedChanges(): void
    {
        $block = new Block();

        // Brand new block: publishedData null, draftData null, positions equal — but
        // publishedData === null is the marker of "not yet published".
        $this->assertTrue($block->hasUnpublishedChanges());
    }

    public function testDraftDataMarksUnpublishedChanges(): void
    {
        $block = $this->makePublishedBlock();
        $this->assertFalse($block->hasUnpublishedChanges());

        $block->setDraftData(['title' => 'New title']);

        $this->assertTrue($block->hasUnpublishedChanges());
    }

    public function testPreviewPositionDifferentMarksUnpublishedChanges(): void
    {
        $block = $this->makePublishedBlock();
        $block->setPreviewPosition(5);

        $this->assertTrue($block->hasUnpublishedChanges());
    }

    public function testDeletedMarksUnpublishedChanges(): void
    {
        $block = $this->makePublishedBlock();
        $block->setDeleted(true);

        $this->assertTrue($block->hasUnpublishedChanges());
    }

    public function testPublishCopiesDraftDataToPublishedData(): void
    {
        $block = new Block();
        $block->setPublishedData(['title' => 'Old']);
        $block->setDraftData(['title' => 'New']);
        $block->setPosition(2);
        $block->setPreviewPosition(5);

        $block->publish();

        $this->assertSame(['title' => 'New'], $block->getPublishedData());
        $this->assertNull($block->getDraftData());
        $this->assertSame(5, $block->getPosition());
        $this->assertSame(5, $block->getPreviewPosition());
        $this->assertFalse($block->hasUnpublishedChanges());
    }

    public function testPublishWithoutDraftDataKeepsPublishedData(): void
    {
        $block = new Block();
        $block->setPublishedData(['title' => 'Stable']);
        $block->setPosition(3);
        $block->setPreviewPosition(7);

        $block->publish();

        $this->assertSame(['title' => 'Stable'], $block->getPublishedData());
        $this->assertNull($block->getDraftData());
        $this->assertSame(7, $block->getPosition());
    }

    public function testPublishOnFreshBlockPromotesDraftToPublished(): void
    {
        // Simulates a brand new block created with default data
        $block = new Block();
        $block->setDraftData(['title' => 'Default']);
        $block->setPreviewPosition(0);

        $block->publish();

        $this->assertSame(['title' => 'Default'], $block->getPublishedData());
        $this->assertNull($block->getDraftData());
        $this->assertFalse($block->hasUnpublishedChanges());
    }

    public function testRevertDraftClearsAllPendingState(): void
    {
        $block = $this->makePublishedBlock();
        $block->setDraftData(['title' => 'Pending']);
        $block->setPreviewPosition(99);
        $block->setDeleted(true);

        $block->revertDraft();

        $this->assertNull($block->getDraftData());
        $this->assertSame($block->getPosition(), $block->getPreviewPosition());
        $this->assertFalse($block->isDeleted());
        $this->assertFalse($block->hasUnpublishedChanges());
    }

    /**
     * Helper: a block in a fully-published, no-pending-changes state.
     */
    private function makePublishedBlock(): Block
    {
        $block = new Block();
        $block->setPublishedData(['title' => 'Hello']);
        $block->setPosition(1);
        $block->setPreviewPosition(1);

        return $block;
    }
}
