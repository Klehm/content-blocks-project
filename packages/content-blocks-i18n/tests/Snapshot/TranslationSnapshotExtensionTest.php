<?php

declare(strict_types=1);

namespace ContentBlocks\I18n\Tests\Snapshot;

use ContentBlocks\Entity\Column;
use ContentBlocks\I18n\Entity\BlockTranslation;
use ContentBlocks\I18n\Entity\ColumnTranslation;
use ContentBlocks\I18n\Locale\TranslationLocales;
use ContentBlocks\I18n\Repository\BlockTranslationRepository;
use ContentBlocks\I18n\Repository\ColumnTranslationRepository;
use ContentBlocks\I18n\Snapshot\TranslationSnapshotExtension;
use ContentBlocks\I18n\Storage\TranslationStore;
use ContentBlocks\I18n\Storage\TranslationWriter;
use ContentBlocks\I18n\Tests\Fixtures\CatalogFactory;
use ContentBlocks\I18n\Tests\Fixtures\Entities;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

/**
 * A section template or a clipboard copy keeps its translations. The copy's
 * collection entries get new ids, so the round trip goes through positions.
 */
final class TranslationSnapshotExtensionTest extends TestCase
{
    private TranslationStore $store;

    public function testATranslatedBlockIsTranslatedOnceCopied(): void
    {
        $source = Entities::block(1, draft: [
            'heading' => 'Welcome',
            'items' => [['_id' => 'aa', 'label' => 'Fast'], ['_id' => 'bb', 'label' => 'Cheap']],
        ]);
        $row = (new BlockTranslation($source, 'fr'))
            ->setDraftValue('heading', 'Bienvenue', 'd-heading')
            ->setDraftValue('items[bb].label', 'Pas cher', 'd-bb');

        $fragment = $this->extension([$row])->capture(['c0.b0' => $source], []);

        $this->assertSame([
            'values' => ['heading' => 'Bienvenue', 'items[#1].label' => 'Pas cher'],
            'digests' => ['heading' => 'd-heading', 'items[#1].label' => 'd-bb'],
        ], $fragment['blocks']['c0.b0']['fr']);

        // The paste minted new ids.
        $copy = Entities::block(2, draft: [
            'heading' => 'Welcome',
            'items' => [['_id' => 'n1', 'label' => 'Fast'], ['_id' => 'n2', 'label' => 'Cheap']],
        ]);
        $extension = $this->extension([]);
        $extension->restore(['c0.b0' => $copy], [], $fragment);

        $restored = $this->store->find($copy, 'fr');
        $this->assertSame(['heading' => 'Bienvenue', 'items[n2].label' => 'Pas cher'], $restored->getDraftValues());
        $this->assertSame('d-bb', $restored->getDraftDigests()['items[n2].label']);
    }

    // A clipboard lives in a browser: whatever it says goes through the gates.
    public function testAForgedFragmentGetsNoFurtherThanTheWriter(): void
    {
        $copy = Entities::block(2, draft: ['heading' => 'Welcome', 'align' => 'center']);
        $extension = $this->extension([]);

        $extension->restore(['c0.b0' => $copy], [], [
            'blocks' => [
                'c0.b0' => [
                    'fr' => ['values' => ['align' => 'left', 'heading' => ['x'], 'nope' => 'y']],
                    'it' => ['values' => ['heading' => 'Benvenuto']],
                    'de' => 'not an entry',
                ],
                'c9.b9' => ['fr' => ['values' => ['heading' => 'Elsewhere']]],
            ],
            'columns' => 'junk',
        ]);

        $this->assertNull($this->store->find($copy, 'fr'));
        $this->assertNull($this->store->find($copy, 'it'));
    }

    public function testATabTitleTravelsWithItsColumn(): void
    {
        $source = $this->column(1, 'Détails');
        $row = (new ColumnTranslation($source, 'fr'))->setDraftValue(ColumnTranslation::LABEL, 'Details', 'd-label');

        $fragment = $this->extension([], [$row])->capture([], ['c0' => $source]);
        $copy = $this->column(2, 'Détails');
        $this->extension([])->restore([], ['c0' => $copy], $fragment);

        $restored = $this->store->findColumn($copy, 'fr');
        $this->assertSame([ColumnTranslation::LABEL => 'Details'], $restored?->getDraftValues());
        $this->assertSame([ColumnTranslation::LABEL => 'd-label'], $restored?->getDraftDigests());
    }

    /**
     * @param list<BlockTranslation>  $rows
     * @param list<ColumnTranslation> $columnRows
     */
    private function extension(array $rows, array $columnRows = []): TranslationSnapshotExtension
    {
        $repository = $this->createStub(BlockTranslationRepository::class);
        $repository->method('findForBlockIds')->willReturn($rows);
        $repository->method('findOneFor')->willReturn(null);
        $columnRepository = $this->createStub(ColumnTranslationRepository::class);
        $columnRepository->method('findForColumnIds')->willReturn($columnRows);
        $columnRepository->method('findOneFor')->willReturn(null);

        $this->store = new TranslationStore($repository, $this->createStub(EntityManagerInterface::class), $columnRepository);
        $writer = new TranslationWriter(
            $this->store,
            CatalogFactory::translatableFields(),
            new TranslationLocales('en', ['fr', 'de']),
            CatalogFactory::metadata(),
        );

        return new TranslationSnapshotExtension($repository, $this->store, $writer, $columnRepository);
    }

    private function column(int $id, string $label): Column
    {
        $column = new Column();
        $column->setDraftSettings(['label' => $label]);
        Entities::id($column, $id);

        return $column;
    }
}
