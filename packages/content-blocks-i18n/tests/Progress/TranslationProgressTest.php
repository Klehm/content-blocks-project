<?php

declare(strict_types=1);

namespace ContentBlocks\I18n\Tests\Progress;

use ContentBlocks\I18n\Field\FieldStatus;
use ContentBlocks\I18n\Field\SourceDigest;
use ContentBlocks\I18n\Field\TranslatableField;
use ContentBlocks\I18n\Locale\TranslationLocales;
use ContentBlocks\I18n\Progress\TranslationInspector;
use ContentBlocks\I18n\Progress\TranslationProgress;
use ContentBlocks\I18n\Repository\BlockTranslationRepository;
use ContentBlocks\I18n\Storage\TranslationStore;
use ContentBlocks\I18n\Tests\Fixtures\CatalogFactory;
use ContentBlocks\I18n\Tests\Fixtures\Entities;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Translation\Translator;

final class TranslationProgressTest extends TestCase
{
    private function field(FieldStatus $status): TranslatableField
    {
        return new TranslatableField('p', 'p', 'L', null, 'text', 'src', null, $status);
    }

    public function testOutdatedCountsAgainstCompletionRatherThanTowardsIt(): void
    {
        // A page whose source was rewritten drops back below 100% — the honest
        // reading, and the one that gets the revision noticed.
        $progress = TranslationProgress::of('fr', [
            $this->field(FieldStatus::TRANSLATED),
            $this->field(FieldStatus::TRANSLATED),
            $this->field(FieldStatus::OUTDATED),
            $this->field(FieldStatus::MISSING),
        ]);

        $this->assertSame(4, $progress->getTotal());
        $this->assertSame(50, $progress->getPercent());
        $this->assertFalse($progress->isComplete());
    }

    public function testNothingToTranslateIsCompleteNotZero(): void
    {
        // An area of images and dividers is done, not 0% translated.
        $progress = TranslationProgress::of('fr', []);

        $this->assertSame(100, $progress->getPercent());
        $this->assertTrue($progress->isComplete());
    }

    public function testProgressAccumulatesAcrossBlocks(): void
    {
        $a = TranslationProgress::of('fr', [$this->field(FieldStatus::TRANSLATED)]);
        $b = TranslationProgress::of('fr', [$this->field(FieldStatus::MISSING)]);

        $total = $a->plus($b);

        $this->assertSame(1, $total->translated);
        $this->assertSame(1, $total->missing);
        $this->assertSame(50, $total->getPercent());
    }

    public function testTheInspectorCountsAWholeAreaAndSkipsBlocksWithNothingToTranslate(): void
    {
        $source = [
            'heading' => 'Welcome',
            'body' => 'We ship worldwide.',
            'align' => 'center',
            'items' => [['_id' => 'aa11', 'label' => 'Fast delivery', 'url' => '/d', 'src' => '']],
        ];

        $repository = $this->createMock(BlockTranslationRepository::class);
        $repository->method('findForArea')->willReturn([]);
        $repository->method('findOneFor')->willReturn(null);

        $inspector = new TranslationInspector(
            new TranslationStore($repository, $this->createMock(EntityManagerInterface::class)),
            CatalogFactory::create(),
            new TranslationLocales('en', ['fr']),
            CatalogFactory::registry(),
            new Translator('en'),
        );

        $area = Entities::area(
            1,
            Entities::block(1, draft: $source, position: 0),
            // Nothing tagged has a value: excluded from the list entirely, so
            // it cannot bury the rows that need work.
            Entities::block(2, draft: ['heading' => '', 'body' => '', 'align' => 'start', 'items' => []], position: 1),
        );

        $views = $inspector->inspectArea($area, 'fr');

        $this->assertCount(1, $views);
        $this->assertSame(4, $inspector->progressForArea($area, 'fr')->getTotal());
        $this->assertSame(0, $inspector->progressForArea($area, 'fr')->getPercent());
    }

    public function testTheInspectorReportsOutdatedSeparately(): void
    {
        $source = ['heading' => 'Welcome', 'body' => '', 'align' => 'a', 'items' => []];

        $block = Entities::block(1, draft: $source);
        $row = new \ContentBlocks\I18n\Entity\BlockTranslation($block, 'fr');
        $row->setDraftPayload(['heading' => 'Bienvenue'], ['heading' => SourceDigest::of('Different')]);

        $repository = $this->createMock(BlockTranslationRepository::class);
        $repository->method('findForArea')->willReturn([$row]);
        $repository->method('findOneFor')->willReturn($row);

        $inspector = new TranslationInspector(
            new TranslationStore($repository, $this->createMock(EntityManagerInterface::class)),
            CatalogFactory::create(),
            new TranslationLocales('en', ['fr']),
            CatalogFactory::registry(),
            new Translator('en'),
        );

        $progress = $inspector->progressForArea(Entities::area(1, $block), 'fr');

        $this->assertSame(1, $progress->outdated);
        $this->assertSame(0, $progress->missing);
        $this->assertSame(0, $progress->getPercent());
    }
}
