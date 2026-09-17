<?php

declare(strict_types=1);

namespace ContentBlocks\Tests\Section;

use ContentBlocks\Entity\Block;
use ContentBlocks\Entity\Column;
use ContentBlocks\Entity\Section;
use ContentBlocks\Section\SectionCloner;
use PHPUnit\Framework\TestCase;

final class SectionClonerTest extends TestCase
{
    public function testClonesLayoutAndDraftSettings(): void
    {
        $section = new Section();
        $section->setLayout(Section::LAYOUT_THREE_COLS);
        $section->setDraftSettings(['classes' => 'hero', 'maxWidth' => '900']);

        $copy = (new SectionCloner())->cloneSection($section);

        $this->assertSame(Section::LAYOUT_THREE_COLS, $copy->getLayout());
        $this->assertSame(['classes' => 'hero', 'maxWidth' => '900'], $copy->getDraftSettings());
        $this->assertNull($copy->getPublishedSettings());
        $this->assertNotSame($section, $copy);
    }

    public function testFallsBackToPublishedSettingsWhenNoDraft(): void
    {
        $section = new Section();
        $section->setLayout(Section::LAYOUT_FULL);
        $section->setPublishedSettings(['classes' => 'old']);

        $copy = (new SectionCloner())->cloneSection($section);

        $this->assertSame(['classes' => 'old'], $copy->getDraftSettings());
    }

    public function testEmptySettingsAreNotCopied(): void
    {
        $section = new Section();
        $section->setLayout(Section::LAYOUT_FULL);
        $section->setDraftSettings([]);
        $section->setPublishedSettings([]);

        $copy = (new SectionCloner())->cloneSection($section);

        $this->assertNull($copy->getDraftSettings());
    }

    public function testClonesColumnsAndBlocksInDraftSlots(): void
    {
        $section = new Section();
        $section->setLayout(Section::LAYOUT_TWO_COLS);

        $columnA = (new Column())->setPreset('col-6')->setPreviewPosition(0);
        $section->addColumn($columnA);
        $columnB = (new Column())->setPreset('col-6')->setPreviewPosition(1);
        $section->addColumn($columnB);

        $blockA = (new Block())
            ->setType('text')
            ->setPublishedData(['text' => 'published'])
            ->setDraftData(['text' => 'draft'])
            ->setPreviewPosition(0);
        $columnA->addBlock($blockA);

        $blockB = (new Block())
            ->setType('title')
            ->setPublishedData(['text' => 'P'])
            ->setPreviewPosition(0);
        $columnB->addBlock($blockB);

        $copy = (new SectionCloner())->cloneSection($section);

        $this->assertCount(2, $copy->getColumns());
        [$copyColA, $copyColB] = array_values($copy->getColumns()->toArray());

        $this->assertSame('col-6', $copyColA->getPreset());
        $this->assertSame(0, $copyColA->getPreviewPosition());
        $this->assertCount(1, $copyColA->getBlocks());
        $clonedBlockA = $copyColA->getBlocks()->first();
        $this->assertNotSame($blockA, $clonedBlockA);
        $this->assertSame('text', $clonedBlockA->getType());
        // Draft preferred over published when present.
        $this->assertSame(['text' => 'draft'], $clonedBlockA->getDraftData());
        $this->assertNull($clonedBlockA->getPublishedData());

        $clonedBlockB = $copyColB->getBlocks()->first();
        $this->assertSame('title', $clonedBlockB->getType());
        // Falls back to published when draft is null.
        $this->assertSame(['text' => 'P'], $clonedBlockB->getDraftData());
    }

    public function testClonesColumnSettingsIntoTheDraft(): void
    {
        $source = new Section();
        $named = new Column();
        $named->setPublishedSettings(['label' => 'Live']);
        $named->setDraftSettings(['label' => 'Draft']);
        $source->addColumn($named);
        $published = new Column();
        $published->setPreviewPosition(1);
        $published->setPublishedSettings(['label' => 'Only live']);
        $source->addColumn($published);
        $source->addColumn((new Column())->setPreviewPosition(2));

        $columns = (new SectionCloner())->cloneSection($source)->getColumns()->toArray();

        $this->assertSame(['label' => 'Draft'], $columns[0]->getDraftSettings());
        $this->assertSame(['label' => 'Only live'], $columns[1]->getDraftSettings());
        $this->assertNull($columns[2]->getDraftSettings());
        $this->assertNull($columns[0]->getPublishedSettings());
    }

    public function testColumnObserversAreToldWhichCopyCameFromWhichSource(): void
    {
        $source = new Section();
        $live = new Column();
        $source->addColumn($live);
        $gone = (new Column())->setDeleted(true);
        $source->addColumn($gone);

        $observer = new class () implements \ContentBlocks\Section\ColumnCloneObserverInterface {
            /** @var list<array{Column, Column}> */
            public array $pairs = [];

            public function columnCloned(Column $source, Column $copy): void
            {
                $this->pairs[] = [$source, $copy];
            }
        };

        $copy = (new SectionCloner(null, new \ContentBlocks\Section\ColumnCloneObserverCollection([$observer])))
            ->cloneSection($source);

        $this->assertCount(1, $observer->pairs);
        $this->assertSame($live, $observer->pairs[0][0]);
        $this->assertSame($copy->getColumns()->first(), $observer->pairs[0][1]);
    }

    public function testSkipsDeletedBlocksAndColumns(): void
    {
        $section = new Section();
        $section->setLayout(Section::LAYOUT_FULL);

        $liveColumn = (new Column())->setPreset('col-12')->setPreviewPosition(0);
        $section->addColumn($liveColumn);
        $deletedColumn = (new Column())->setPreset('col-12')->setPreviewPosition(1);
        $deletedColumn->setDeleted(true);
        $section->addColumn($deletedColumn);

        $liveBlock = (new Block())->setType('text')->setDraftData(['text' => 'keep']);
        $liveColumn->addBlock($liveBlock);

        $deletedBlock = (new Block())->setType('text')->setDraftData(['text' => 'drop']);
        $deletedBlock->setDeleted(true);
        $liveColumn->addBlock($deletedBlock);

        $copy = (new SectionCloner())->cloneSection($section);

        $this->assertCount(1, $copy->getColumns(), 'soft-deleted columns must be skipped');
        $clonedColumn = $copy->getColumns()->first();
        $this->assertCount(1, $clonedColumn->getBlocks(), 'soft-deleted blocks must be skipped');
        $this->assertSame(['text' => 'keep'], $clonedColumn->getBlocks()->first()->getDraftData());
    }

    public function testCopyIsDetachedFromAnyContentArea(): void
    {
        $section = new Section();
        $section->setLayout(Section::LAYOUT_FULL);

        $copy = (new SectionCloner())->cloneSection($section);

        $this->assertNull($copy->getContentArea());
        $this->assertNull($copy->getId());
    }
}
