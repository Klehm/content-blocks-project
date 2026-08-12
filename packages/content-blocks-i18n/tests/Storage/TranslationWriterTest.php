<?php

declare(strict_types=1);

namespace ContentBlocks\I18n\Tests\Storage;

use ContentBlocks\I18n\Entity\BlockTranslation;
use ContentBlocks\I18n\Field\SourceDigest;
use ContentBlocks\I18n\Locale\TranslationLocales;
use ContentBlocks\I18n\Repository\BlockTranslationRepository;
use ContentBlocks\I18n\Storage\TranslationStore;
use ContentBlocks\I18n\Storage\TranslationWriter;
use ContentBlocks\I18n\Tests\Fixtures\CatalogFactory;
use ContentBlocks\I18n\Tests\Fixtures\Entities;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

/**
 * The write gate. Everything reaching it is untrusted — a workbench payload or
 * a remote engine's output — so each refusal is pinned individually.
 */
final class TranslationWriterTest extends TestCase
{
    private TranslationStore $store;

    private function writer(): TranslationWriter
    {
        $repository = $this->createMock(BlockTranslationRepository::class);
        $repository->method('findOneFor')->willReturn(null);

        $this->store = new TranslationStore($repository, $this->createMock(EntityManagerInterface::class));

        return new TranslationWriter(
            $this->store,
            CatalogFactory::translatableFields(),
            new TranslationLocales('en', ['fr', 'de']),
        );
    }

    /** @return array<string, mixed> */
    private function source(): array
    {
        return [
            'heading' => 'Welcome',
            'body' => 'We ship worldwide.',
            'align' => 'center',
            'items' => [['_id' => 'aa11', 'label' => 'Fast delivery', 'url' => '/d', 'src' => '']],
        ];
    }

    public function testWritesATaggedFieldIntoTheDraftWithItsSourceDigest(): void
    {
        $writer = $this->writer();
        $block = Entities::block(1, draft: $this->source());

        $result = $writer->write($block, 'fr', ['heading' => 'Bienvenue']);

        $this->assertSame(['heading'], $result->written);

        $row = $this->store->find($block, 'fr');
        $this->assertInstanceOf(BlockTranslation::class, $row);
        $this->assertSame(['heading' => 'Bienvenue'], $row->getDraftValues());
        $this->assertSame(SourceDigest::of('Welcome'), $row->getDraftDigests()['heading']);
        // Draft only — Publish is what makes a translation public.
        $this->assertNull($row->getPublishedValues());
    }

    public function testRefusesAFieldTheBlockTypeDoesNotTagAsTranslatable(): void
    {
        // Tags are the allow-list, exactly as a block's form is the allow-list
        // for its own data. Refusing at write (rather than ignoring at render)
        // is what gets the mistake back to the caller.
        $result = $this->writer()->write(Entities::block(1, draft: $this->source()), 'fr', ['align' => 'centre']);

        $this->assertSame([], $result->written);
        $this->assertSame(['align' => 'not_translatable'], $result->rejected);
    }

    public function testRefusesAPathThatDoesNotExistInTheSource(): void
    {
        // A card the editor deleted in another tab, or a field the block type
        // dropped in a release.
        $result = $this->writer()->write(Entities::block(1, draft: $this->source()), 'fr', [
            'items[gone].label' => 'x',
        ]);

        $this->assertSame(['items[gone].label' => 'unknown_path'], $result->rejected);
    }

    public function testOneBadPathDoesNotCostTheRestOfTheBatch(): void
    {
        // The workbench autosaves several fields at once; losing nine good
        // writes to one stale reference would be the wrong trade.
        $result = $this->writer()->write(Entities::block(1, draft: $this->source()), 'fr', [
            'heading' => 'Bienvenue',
            'items[gone].label' => 'x',
            'body' => 'Nous livrons partout.',
        ]);

        $this->assertSame(['heading', 'body'], $result->written);
        $this->assertArrayHasKey('items[gone].label', $result->rejected);
    }

    public function testRefusesALocaleThatIsNotAConfiguredTarget(): void
    {
        $result = $this->writer()->write(Entities::block(1, draft: $this->source()), 'it', ['heading' => 'Benvenuto']);

        $this->assertSame(['heading' => 'unknown_locale'], $result->rejected);
    }

    public function testRefusesTheSourceLocaleAsATarget(): void
    {
        // The source *is* `Block.data`; a row for it would be a second, silently
        // diverging copy of the same text.
        $result = $this->writer()->write(Entities::block(1, draft: $this->source()), 'en', ['heading' => 'Hi']);

        $this->assertSame(['heading' => 'unknown_locale'], $result->rejected);
    }

    public function testNullClearsATranslationWhileEmptyStringStoresABlank(): void
    {
        // The distinction is load-bearing: a card with an optional subtitle in
        // English and none in German needs the blank, because clearing would
        // fall back and print the English subtitle on the German page.
        $writer = $this->writer();
        $block = Entities::block(1, draft: $this->source());

        $writer->write($block, 'fr', ['heading' => 'Bienvenue', 'body' => 'Texte']);
        $writer->write($block, 'fr', ['heading' => null, 'body' => '']);

        $row = $this->store->find($block, 'fr');
        $this->assertArrayNotHasKey('heading', $row->getDraftValues());
        $this->assertSame('', $row->getDraftValues()['body']);
    }

    public function testAWhollyRejectedBatchLeavesNoRowBehind(): void
    {
        $writer = $this->writer();
        $block = Entities::block(1, draft: $this->source());

        $writer->write($block, 'fr', ['align' => 'centre']);

        $this->assertNull($this->store->find($block, 'fr'));
    }

    public function testMarkUpToDateRefreshesTheDigestWithoutChangingTheValue(): void
    {
        $writer = $this->writer();
        $source = $this->source();
        $block = Entities::block(1, draft: $source);

        $writer->write($block, 'fr', ['heading' => 'Bienvenue']);

        // The English is rewritten; the French still says the right thing.
        $source['heading'] = 'Welcome!';
        $block->setDraftData($source);

        $result = $writer->markUpToDate($block, 'fr', ['heading']);
        $row = $this->store->find($block, 'fr');

        $this->assertSame(['heading'], $result->written);
        $this->assertSame('Bienvenue', $row->getDraftValues()['heading']);
        $this->assertSame(SourceDigest::of('Welcome!'), $row->getDraftDigests()['heading']);
    }

    public function testMarkUpToDateRefusesAFieldWithNoTranslation(): void
    {
        $writer = $this->writer();
        $block = Entities::block(1, draft: $this->source());

        $result = $writer->markUpToDate($block, 'fr', ['heading']);

        $this->assertSame(['heading' => 'no_translation'], $result->rejected);
    }

    public function testAPartialEditDoesNotUnpublishTheBlocksOtherFields(): void
    {
        // The first draft write copies the published payload forward; without
        // that, editing one field would silently drop every other translation
        // the block already had live.
        $repository = $this->createMock(BlockTranslationRepository::class);
        $block = Entities::block(1, draft: $this->source());

        $row = new BlockTranslation($block, 'fr');
        $row->setPublishedPayload(
            ['heading' => 'Bienvenue', 'body' => 'Texte'],
            ['heading' => SourceDigest::of('Welcome'), 'body' => SourceDigest::of('We ship worldwide.')],
        );

        $repository->method('findOneFor')->willReturn($row);
        $store = new TranslationStore($repository, $this->createMock(EntityManagerInterface::class));

        $writer = new TranslationWriter($store, CatalogFactory::translatableFields(), new TranslationLocales('en', ['fr']));
        $writer->write($block, 'fr', ['heading' => 'Bienvenue !']);

        $this->assertSame(
            ['heading' => 'Bienvenue !', 'body' => 'Texte'],
            $row->getDraftValues(),
        );
    }
}
