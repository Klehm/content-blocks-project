<?php

declare(strict_types=1);

namespace ContentBlocks\Tests\Service;

use ContentBlocks\Entity\Block;
use ContentBlocks\Entity\Column;
use ContentBlocks\Entity\ContentArea;
use ContentBlocks\Entity\Section;
use ContentBlocks\Service\ContentAreaPublisher;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

final class ContentAreaPublisherTest extends TestCase
{
    /** @var list<object> */
    private array $removed = [];
    private int $flushCount = 0;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->removed = [];
        $this->flushCount = 0;

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('remove')->willReturnCallback(function (object $entity): void {
            $this->removed[] = $entity;
        });
        $em->method('flush')->willReturnCallback(function (): void {
            $this->flushCount++;
        });
        $this->em = $em;
    }

    // ---------- publish() ----------

    public function testPublishCopiesDraftDataAndSyncsBlockPosition(): void
    {
        $area = new ContentArea();
        $section = $this->makeSection($area, position: 0, previewPosition: 0);
        $column = $this->makeColumn($section, position: 0, previewPosition: 0);
        $block = $this->makeBlock($column, type: 'text', publishedData: ['title' => 'Old'], draftData: ['title' => 'New'], position: 0, previewPosition: 0);

        (new ContentAreaPublisher($this->em))->publish($area);

        $this->assertSame(['title' => 'New'], $block->getPublishedData());
        $this->assertNull($block->getDraftData());
        $this->assertEmpty($this->removed);
        $this->assertSame(1, $this->flushCount);
    }

    public function testPublishSyncsSectionAndColumnPositions(): void
    {
        $area = new ContentArea();
        $section = $this->makeSection($area, position: 0, previewPosition: 3);
        $column = $this->makeColumn($section, position: 1, previewPosition: 5);
        $this->makeBlock($column, type: 'text', publishedData: ['title' => 'X'], position: 0, previewPosition: 0);

        (new ContentAreaPublisher($this->em))->publish($area);

        $this->assertSame(3, $section->getPosition());
        $this->assertSame(5, $column->getPosition());
    }

    public function testPublishRemovesDeletedSection(): void
    {
        $area = new ContentArea();
        $section = $this->makeSection($area, position: 0, previewPosition: 0);
        $section->setDeleted(true);
        $column = $this->makeColumn($section, position: 0, previewPosition: 0);
        $this->makeBlock($column, type: 'text', publishedData: ['title' => 'X'], position: 0, previewPosition: 0);

        (new ContentAreaPublisher($this->em))->publish($area);

        // Only the section is explicitly removed — Doctrine cascade handles the
        // descendants (and is not exercised in this unit test).
        $this->assertSame([$section], $this->removed);
    }

    public function testPublishRemovesDeletedColumn(): void
    {
        $area = new ContentArea();
        $section = $this->makeSection($area, position: 0, previewPosition: 0);
        $column = $this->makeColumn($section, position: 0, previewPosition: 0);
        $column->setDeleted(true);
        $this->makeBlock($column, type: 'text', publishedData: ['title' => 'X'], position: 0, previewPosition: 0);

        (new ContentAreaPublisher($this->em))->publish($area);

        $this->assertSame([$column], $this->removed);
    }

    public function testPublishRemovesDeletedBlock(): void
    {
        $area = new ContentArea();
        $section = $this->makeSection($area, position: 0, previewPosition: 0);
        $column = $this->makeColumn($section, position: 0, previewPosition: 0);
        $block = $this->makeBlock($column, type: 'text', publishedData: ['title' => 'X'], position: 0, previewPosition: 0);
        $block->setDeleted(true);

        (new ContentAreaPublisher($this->em))->publish($area);

        $this->assertSame([$block], $this->removed);
    }

    public function testPublishHandlesMixedTree(): void
    {
        $area = new ContentArea();

        // Section A: kept, with one block edited and one block deleted.
        $sectionA = $this->makeSection($area, position: 0, previewPosition: 0);
        $columnA = $this->makeColumn($sectionA, position: 0, previewPosition: 0);
        $editedBlock = $this->makeBlock($columnA, type: 'text', publishedData: ['title' => 'Old'], draftData: ['title' => 'Updated'], position: 0, previewPosition: 0);
        $blockToDelete = $this->makeBlock($columnA, type: 'text', publishedData: ['title' => 'Stale'], position: 1, previewPosition: 1);
        $blockToDelete->setDeleted(true);

        // Section B: marked for deletion.
        $sectionB = $this->makeSection($area, position: 1, previewPosition: 1);
        $sectionB->setDeleted(true);
        $columnB = $this->makeColumn($sectionB, position: 0, previewPosition: 0);
        $this->makeBlock($columnB, type: 'text', publishedData: ['title' => 'WillCascade'], position: 0, previewPosition: 0);

        (new ContentAreaPublisher($this->em))->publish($area);

        $this->assertCount(2, $this->removed);
        $this->assertContains($blockToDelete, $this->removed);
        $this->assertContains($sectionB, $this->removed);
        $this->assertSame(['title' => 'Updated'], $editedBlock->getPublishedData());
        $this->assertNull($editedBlock->getDraftData());
        $this->assertSame(1, $this->flushCount);
    }

    // ---------- discardDraft() ----------

    public function testDiscardRevertsDraftDataOnPublishedBlock(): void
    {
        $area = new ContentArea();
        $section = $this->makeSection($area, position: 1, previewPosition: 5);
        $column = $this->makeColumn($section, position: 0, previewPosition: 0);
        $block = $this->makeBlock($column, type: 'text', publishedData: ['title' => 'Stable'], draftData: ['title' => 'Pending'], position: 2, previewPosition: 7);
        $block->setDeleted(true);

        (new ContentAreaPublisher($this->em))->discardDraft($area);

        $this->assertNull($block->getDraftData());
        $this->assertSame(['title' => 'Stable'], $block->getPublishedData());
        $this->assertSame(2, $block->getPreviewPosition());
        $this->assertFalse($block->isDeleted());

        $this->assertSame(1, $section->getPreviewPosition());
        $this->assertSame(0, $column->getPreviewPosition());
        $this->assertEmpty($this->removed);
    }

    public function testDiscardRemovesNeverPublishedBlock(): void
    {
        $area = new ContentArea();
        $section = $this->makeSection($area, position: 0, previewPosition: 0);
        $column = $this->makeColumn($section, position: 0, previewPosition: 0);
        $newBlock = $this->makeBlock($column, type: 'text', publishedData: null, draftData: ['title' => 'Just added'], position: 0, previewPosition: 0);

        (new ContentAreaPublisher($this->em))->discardDraft($area);

        $this->assertSame([$newBlock], $this->removed);
    }

    public function testDiscardLeavesAlreadyPublishedBlocksAlone(): void
    {
        $area = new ContentArea();
        $section = $this->makeSection($area, position: 0, previewPosition: 0);
        $column = $this->makeColumn($section, position: 0, previewPosition: 0);
        $stable = $this->makeBlock($column, type: 'text', publishedData: ['title' => 'Stable'], position: 0, previewPosition: 0);

        (new ContentAreaPublisher($this->em))->discardDraft($area);

        $this->assertSame(['title' => 'Stable'], $stable->getPublishedData());
        $this->assertNull($stable->getDraftData());
        $this->assertEmpty($this->removed);
        $this->assertSame(1, $this->flushCount);
    }

    public function testDiscardClearsDeletedFlagsUpTheTree(): void
    {
        $area = new ContentArea();
        $section = $this->makeSection($area, position: 0, previewPosition: 0);
        $section->setDeleted(true);
        $column = $this->makeColumn($section, position: 0, previewPosition: 0);
        $column->setDeleted(true);

        (new ContentAreaPublisher($this->em))->discardDraft($area);

        $this->assertFalse($section->isDeleted());
        $this->assertFalse($column->isDeleted());
        $this->assertEmpty($this->removed);
    }

    public function testDiscardRemovesNeverPublishedSection(): void
    {
        $area = new ContentArea();
        $newSection = $this->makeSection($area, position: 0, previewPosition: 3, published: false);
        $this->makeColumn($newSection, position: 0, previewPosition: 0, published: false);

        (new ContentAreaPublisher($this->em))->discardDraft($area);

        // Doctrine cascade-removes the column on flush — we only expect the
        // section in the explicit-remove list.
        $this->assertSame([$newSection], $this->removed);
    }

    public function testDiscardRemovesNeverPublishedColumnInsidePublishedSection(): void
    {
        $area = new ContentArea();
        $section = $this->makeSection($area, position: 0, previewPosition: 0);
        $newColumn = $this->makeColumn($section, position: 0, previewPosition: 1, published: false);

        (new ContentAreaPublisher($this->em))->discardDraft($area);

        $this->assertSame([$newColumn], $this->removed);
    }

    // ---------- factories ----------

    private function makeSection(ContentArea $area, int $position, int $previewPosition, bool $published = true): Section
    {
        $section = new Section();
        $section->setLayout(Section::LAYOUT_FULL);
        $section->setPosition($position);
        $section->setPreviewPosition($previewPosition);
        $area->addSection($section);

        if ($published) {
            // Test fixtures default to "previously published" so discard reverts
            // them rather than removing them. Pass published: false to simulate
            // a brand-new section.
            $this->markPublished($section);
        }

        return $section;
    }

    private function makeColumn(Section $section, int $position, int $previewPosition, bool $published = true): Column
    {
        $column = new Column();
        $column->setPosition($position);
        $column->setPreviewPosition($previewPosition);
        $section->addColumn($column);

        if ($published) {
            $this->markPublished($column);
        }

        return $column;
    }

    /**
     * @param array<string, mixed>|null $publishedData
     * @param array<string, mixed>|null $draftData
     */
    private function makeBlock(
        Column $column,
        string $type,
        ?array $publishedData,
        ?array $draftData = null,
        int $position = 0,
        int $previewPosition = 0,
    ): Block {
        $block = new Block();
        $block->setType($type);
        $block->setPublishedData($publishedData);
        $block->setDraftData($draftData);
        $block->setPosition($position);
        $block->setPreviewPosition($previewPosition);
        $column->addBlock($block);

        return $block;
    }

    /**
     * Stamps `publishedAt` without going through publish() (which would also
     * sync position from previewPosition and break test fixtures that
     * intentionally keep them divergent).
     */
    private function markPublished(Section|Column $entity): void
    {
        $ref = new \ReflectionProperty($entity::class, 'publishedAt');
        $ref->setValue($entity, new \DateTimeImmutable());
    }
}
