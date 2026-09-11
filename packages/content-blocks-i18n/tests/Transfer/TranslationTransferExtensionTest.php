<?php

declare(strict_types=1);

namespace ContentBlocks\I18n\Tests\Transfer;

use ContentBlocks\Asset\AssetReferenceCollector;
use ContentBlocks\Asset\AssetResolverInterface;
use ContentBlocks\Entity\Block;
use ContentBlocks\Entity\ContentArea;
use ContentBlocks\I18n\Entity\BlockTranslation;
use ContentBlocks\I18n\Repository\BlockTranslationRepository;
use ContentBlocks\I18n\Tests\Fixtures\Entities;
use ContentBlocks\I18n\Transfer\TranslationTransferExtension;
use ContentBlocks\Transfer\AssetRewriter;
use ContentBlocks\Transfer\AssetTokenizer;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

/**
 * The rows an export would otherwise leave behind — a page that travelled and
 * came back structurally identical but untranslated.
 */
final class TranslationTransferExtensionTest extends TestCase
{
    /** @var list<object> */
    private array $persisted = [];

    /** @var array<string, string> path => binary */
    private array $files = [];

    public function testExportCarriesEveryLocaleOfEveryExportedBlock(): void
    {
        $block = Entities::block(7, draft: ['title' => 'Hello']);
        $rows = [
            $this->row($block, 'fr', ['title' => 'Bonjour'], ['title' => 'digest-fr']),
            $this->row($block, 'de', ['title' => 'Hallo'], ['title' => 'digest-de']),
        ];

        $fragment = $this->extension($rows)->export(
            Entities::area(1, $block),
            ['s0.c0.b0' => $block],
            $this->tokenizer(),
        );

        self::assertSame([
            'blocks' => [
                's0.c0.b0' => [
                    'fr' => ['values' => ['title' => 'Bonjour'], 'digests' => ['title' => 'digest-fr']],
                    'de' => ['values' => ['title' => 'Hallo'], 'digests' => ['title' => 'digest-de']],
                ],
            ],
        ], $fragment);
    }

    public function testExportPrefersTheDraftAndSkipsEmptyRows(): void
    {
        $block = Entities::block(7);
        $row = $this->row($block, 'fr', ['title' => 'Publié'], ['title' => 'old']);
        $row->publish();
        $row->setDraftValue('title', 'Brouillon', 'fresh');

        $fragment = $this->extension([$row, $this->row(Entities::block(8), 'de', [], [])])->export(
            Entities::area(1, $block),
            ['s0.c0.b0' => $block],
            $this->tokenizer(),
        );

        self::assertSame(
            ['title' => 'Brouillon'],
            $fragment['blocks']['s0.c0.b0']['fr']['values'],
        );
        // Digests are paired with the values they were captured against.
        self::assertSame(['title' => 'fresh'], $fragment['blocks']['s0.c0.b0']['fr']['digests']);
    }

    public function testExportCarriesAFileOnlyATranslationReferences(): void
    {
        $this->files['/uploads/fr-hero.png'] = 'french-bytes';
        $hash = hash('sha256', 'french-bytes');

        $block = Entities::block(7, draft: ['body' => '<p>Hello</p>']);
        $row = $this->row($block, 'fr', ['body' => '<p><img src="/uploads/fr-hero.png"></p>'], []);
        $tokenizer = $this->tokenizer();

        $fragment = $this->extension([$row])->export(
            Entities::area(1, $block),
            ['s0.c0.b0' => $block],
            $tokenizer,
        );

        self::assertSame(
            ['body' => '<p><img src="asset://' . $hash . '"></p>'],
            $fragment['blocks']['s0.c0.b0']['fr']['values'],
        );
        self::assertSame('french-bytes', base64_decode($tokenizer->assets()[$hash]['data'], true));
    }

    public function testExportIsEmptyWithoutRows(): void
    {
        $block = Entities::block(7);

        self::assertSame([], $this->extension([])->export(
            Entities::area(1, $block),
            ['s0.c0.b0' => $block],
            $this->tokenizer(),
        ));
    }

    public function testImportWritesDraftRowsOnTheBlockTheRefNames(): void
    {
        $block = new Block();
        $fragment = ['blocks' => ['s0.c0.b0' => [
            'fr' => ['values' => ['title' => 'Bonjour'], 'digests' => ['title' => 'digest-fr']],
        ]]];

        $this->extension([])->import(new ContentArea(), ['s0.c0.b0' => $block], $fragment, new AssetRewriter());

        self::assertCount(1, $this->persisted);
        $row = $this->persisted[0];
        self::assertInstanceOf(BlockTranslation::class, $row);
        self::assertSame($block, $row->getBlock());
        self::assertSame('fr', $row->getLocale());
        self::assertSame(['title' => 'Bonjour'], $row->getDraftValues());
        self::assertSame(['title' => 'digest-fr'], $row->getDraftDigests());
        // Draft, like every write: the area's Publish commits it.
        self::assertNull($row->getPublishedValues());
    }

    public function testImportRewritesAssetTokensInsideTranslatedMarkup(): void
    {
        $fragment = ['blocks' => ['s0.c0.b0' => [
            'fr' => ['values' => ['body' => '<img src="asset://abc123">'], 'digests' => []],
        ]]];

        $this->extension([])->import(
            new ContentArea(),
            ['s0.c0.b0' => new Block()],
            $fragment,
            new AssetRewriter(['abc123' => '/uploads/stored-here.png']),
        );

        $row = $this->persisted[0];
        self::assertInstanceOf(BlockTranslation::class, $row);
        self::assertSame(['body' => '<img src="/uploads/stored-here.png">'], $row->getDraftValues());
    }

    public function testRowsOfASkippedBlockAreDroppedWithIt(): void
    {
        $fragment = ['blocks' => [
            's0.c0.b0' => ['fr' => ['values' => ['title' => 'kept'], 'digests' => []]],
            's0.c0.b1' => ['fr' => ['values' => ['title' => 'gone'], 'digests' => []]],
        ]];

        $this->extension([])->import(
            new ContentArea(),
            ['s0.c0.b0' => new Block()],
            $fragment,
            new AssetRewriter(),
        );

        self::assertCount(1, $this->persisted);
    }

    public function testAMalformedFragmentWritesNothing(): void
    {
        // The payload is a file that travelled: every shape is re-checked.
        $fragment = ['blocks' => [
            's0.c0.b0' => [
                '' => ['values' => ['title' => 'no locale']],
                'fr' => ['values' => 'not-a-map'],
                'de' => 'not-an-entry',
                'es' => ['values' => []],
            ],
        ]];

        $this->extension([])->import(
            new ContentArea(),
            ['s0.c0.b0' => new Block()],
            $fragment,
            new AssetRewriter(),
        );

        self::assertSame([], $this->persisted);
    }

    public function testNonStringDigestsAreDroppedWithoutCostingTheValues(): void
    {
        $fragment = ['blocks' => ['s0.c0.b0' => [
            'fr' => ['values' => ['title' => 'Bonjour'], 'digests' => ['title' => 42]],
        ]]];

        $this->extension([])->import(
            new ContentArea(),
            ['s0.c0.b0' => new Block()],
            $fragment,
            new AssetRewriter(),
        );

        $row = $this->persisted[0];
        self::assertInstanceOf(BlockTranslation::class, $row);
        self::assertSame(['title' => 'Bonjour'], $row->getDraftValues());
        self::assertSame([], $row->getDraftDigests(), 'no digest reads as never captured');
    }

    /**
     * @param array<string, mixed>  $values
     * @param array<string, string> $digests
     */
    private function row(Block $block, string $locale, array $values, array $digests): BlockTranslation
    {
        $row = new BlockTranslation($block, $locale);
        $row->setDraftPayload($values, $digests);

        return $row;
    }

    /**
     * @param list<BlockTranslation> $rows
     */
    private function extension(array $rows): TranslationTransferExtension
    {
        $repository = $this->createMock(BlockTranslationRepository::class);
        $repository->method('findForBlockIds')->willReturn($rows);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('persist')->willReturnCallback(function (object $entity): void {
            $this->persisted[] = $entity;
        });

        return new TranslationTransferExtension($repository, $em);
    }

    private function tokenizer(): AssetTokenizer
    {
        $resolver = $this->createMock(AssetResolverInterface::class);
        $resolver->method('isAssetPath')->willReturnCallback(
            fn (string $value) => str_starts_with($value, '/uploads/'),
        );
        $resolver->method('read')->willReturnCallback(
            fn (string $path) => $this->files[$path] ?? null,
        );

        return new AssetTokenizer(new AssetReferenceCollector($resolver), $resolver);
    }
}
