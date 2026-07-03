<?php

declare(strict_types=1);

namespace ContentBlocks\Tests\Service;

use ContentBlocks\Entity\Block;
use ContentBlocks\Entity\Column;
use ContentBlocks\Entity\Section;
use ContentBlocks\Service\SectionTemplateSerializer;
use PHPUnit\Framework\TestCase;

final class SectionTemplateSerializerTest extends TestCase
{
    public function testSerializesLayoutColumnsBlocksAndDistinctTypes(): void
    {
        $section = new Section();
        $section->setLayout(Section::LAYOUT_TWO_COLS);

        $colA = (new Column())->setPreset('col-6')->setPreviewPosition(0);
        $section->addColumn($colA);
        $colB = (new Column())->setPreset('col-6')->setPreviewPosition(1);
        $section->addColumn($colB);

        $colA->addBlock((new Block())->setType('text')->setDraftData(['text' => 'A'])->setPreviewPosition(0));
        $colA->addBlock((new Block())->setType('title')->setDraftData(['text' => 'B'])->setPreviewPosition(1));
        $colB->addBlock((new Block())->setType('text')->setDraftData(['text' => 'C'])->setPreviewPosition(0));

        $result = (new SectionTemplateSerializer())->serialize($section);

        $this->assertSame(SectionTemplateSerializer::FORMAT, $result['payload']['format']);
        $this->assertSame(Section::LAYOUT_TWO_COLS, $result['payload']['layout']);
        $this->assertCount(2, $result['payload']['columns']);
        $this->assertSame('col-6', $result['payload']['columns'][0]['preset']);
        $this->assertCount(2, $result['payload']['columns'][0]['blocks']);
        $this->assertSame('text', $result['payload']['columns'][0]['blocks'][0]['type']);
        $this->assertSame(['text' => 'A'], $result['payload']['columns'][0]['blocks'][0]['data']);

        // Distinct types only, no duplicates.
        $this->assertSame(['text', 'title'], $result['blockTypes']);
    }

    public function testDraftSettingsWinAndEmptyBecomesNull(): void
    {
        $section = new Section();
        $section->setLayout(Section::LAYOUT_FULL);
        $section->setPublishedSettings(['classes' => 'old']);
        $section->setDraftSettings(['classes' => 'new']);

        $result = (new SectionTemplateSerializer())->serialize($section);
        $this->assertSame(['classes' => 'new'], $result['payload']['settings']);

        $empty = new Section();
        $empty->setDraftSettings([]);
        $this->assertNull((new SectionTemplateSerializer())->serialize($empty)['payload']['settings']);
    }

    public function testBlockDataFallsBackToPublishedThenEmpty(): void
    {
        $section = new Section();
        $col = (new Column())->setPreset('col-12')->setPreviewPosition(0);
        $section->addColumn($col);
        $col->addBlock((new Block())->setType('title')->setPublishedData(['text' => 'pub'])->setPreviewPosition(0));
        $col->addBlock((new Block())->setType('image')->setPreviewPosition(1));

        $blocks = (new SectionTemplateSerializer())->serialize($section)['payload']['columns'][0]['blocks'];
        $this->assertSame(['text' => 'pub'], $blocks[0]['data']);
        $this->assertSame([], $blocks[1]['data']);
    }

    public function testSkipsDeletedColumnsAndBlocksAndSortsByPreviewPosition(): void
    {
        $section = new Section();

        // Deliberately added out of order to prove previewPosition sorting.
        $second = (new Column())->setPreset('col-6')->setPreviewPosition(1);
        $section->addColumn($second);
        $first = (new Column())->setPreset('col-6')->setPreviewPosition(0);
        $section->addColumn($first);
        $ghost = (new Column())->setPreset('col-6')->setPreviewPosition(2);
        $ghost->setDeleted(true);
        $section->addColumn($ghost);

        $keep = (new Block())->setType('text')->setDraftData(['text' => 'keep'])->setPreviewPosition(0);
        $first->addBlock($keep);
        $drop = (new Block())->setType('text')->setDraftData(['text' => 'drop'])->setPreviewPosition(1);
        $drop->setDeleted(true);
        $first->addBlock($drop);

        $payload = (new SectionTemplateSerializer())->serialize($section)['payload'];

        $this->assertCount(2, $payload['columns'], 'deleted column is skipped');
        $this->assertSame('keep', $payload['columns'][0]['blocks'][0]['data']['text']);
        $this->assertCount(1, $payload['columns'][0]['blocks'], 'deleted block is skipped');
    }

    public function testAssetPathsAreKeptVerbatimNotEmbedded(): void
    {
        $section = new Section();
        $col = (new Column())->setPreset('col-12')->setPreviewPosition(0);
        $section->addColumn($col);
        $col->addBlock(
            (new Block())
                ->setType('image')
                ->setDraftData(['src' => '/uploads/content-blocks/photo.jpg', 'alt' => 'x'])
                ->setPreviewPosition(0),
        );

        $data = (new SectionTemplateSerializer())->serialize($section)['payload']['columns'][0]['blocks'][0]['data'];
        $this->assertSame('/uploads/content-blocks/photo.jpg', $data['src']);
    }
}
