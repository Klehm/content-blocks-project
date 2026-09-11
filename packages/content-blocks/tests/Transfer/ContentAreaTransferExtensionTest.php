<?php

declare(strict_types=1);

namespace ContentBlocks\Tests\Transfer;

use ContentBlocks\Asset\AssetResolverInterface;
use ContentBlocks\Block\BlockDataKeys;
use ContentBlocks\BlockType\BlockTypeRegistry;
use ContentBlocks\Entity\Block;
use ContentBlocks\Entity\Column;
use ContentBlocks\Entity\ContentArea;
use ContentBlocks\Entity\Section;
use ContentBlocks\Form\Extension\BlockFormExtensionCollection;
use ContentBlocks\Form\Type\BlockFormType;
use ContentBlocks\Transfer\AssetRewriter;
use ContentBlocks\Transfer\AssetTokenizer;
use ContentBlocks\Transfer\ContentAreaExporter;
use ContentBlocks\Transfer\ContentAreaImporter;
use ContentBlocks\Transfer\ContentAreaTransferExtensionInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Form\Forms;

/**
 * The seam that carries what a bundle stores beside a block — i18n's
 * translation rows being the shipped case.
 */
final class ContentAreaTransferExtensionTest extends TestCase
{
    /** @var array<string, string> path => binary */
    private array $files = [];

    private function makeResolver(): AssetResolverInterface
    {
        $resolver = $this->createMock(AssetResolverInterface::class);
        $resolver->method('isAssetPath')->willReturnCallback(
            fn (string $value) => str_starts_with($value, '/uploads/'),
        );
        $resolver->method('read')->willReturnCallback(
            fn (string $path) => $this->files[$path] ?? null,
        );
        $resolver->method('store')->willReturnCallback(
            fn (string $binary, string $extension) => sprintf('/uploads/imported.%s', $extension),
        );

        return $resolver;
    }

    private function importer(
        AssetResolverInterface $resolver,
        ContentAreaTransferExtensionInterface $extension,
    ): ContentAreaImporter {
        $registry = new BlockTypeRegistry();
        $registry->register(new FakeTextBlockType());
        $registry->register(new FakeImageBlockType());

        $factory = Forms::createFormFactoryBuilder()
            ->addType(new BlockFormType(new BlockFormExtensionCollection()))
            ->getFormFactory();

        return new ContentAreaImporter(
            $resolver,
            $registry,
            new BlockDataKeys($registry, $factory),
            extensions: [$extension],
        );
    }

    /** Two sections, the second holding a live and a soft-deleted block. */
    private function makeArea(): ContentArea
    {
        $area = new ContentArea();

        foreach ([0, 1] as $s) {
            $section = new Section();
            $section->setLayout(Section::LAYOUT_FULL);
            $section->setPreviewPosition($s);
            $area->addSection($section);

            $column = new Column();
            $column->setPreset('col-12');
            $column->setPreviewPosition(0);
            $section->addColumn($column);

            $block = new Block();
            $block->setType('text');
            $block->setDraftData(['content' => 'block ' . $s]);
            $block->setPreviewPosition(0);
            $column->addBlock($block);
        }

        $dead = new Block();
        $dead->setType('text');
        $dead->setDraftData(['content' => 'dead']);
        $dead->setPreviewPosition(1);
        $dead->setDeleted(true);
        $area->getSections()[1]->getColumns()[0]->addBlock($dead);

        return $area;
    }

    public function testExportHandsEveryLiveBlockKeyedByItsPayloadRef(): void
    {
        $extension = new RecordingTransferExtension();
        $payload = (new ContentAreaExporter($this->makeResolver(), extensions: [$extension]))
            ->export($this->makeArea());

        $this->assertSame(['s0.c0.b0', 's1.c0.b0'], array_keys($extension->exportedBlocks));
        // The map's keys are the payload's own refs, or a fragment could not
        // address a block across an import.
        $this->assertSame(
            's1.c0.b0',
            $payload['contentArea']['sections'][1]['columns'][0]['blocks'][0]['ref'],
        );
        $this->assertSame(
            'block 1',
            $extension->exportedBlocks['s1.c0.b0']->getDraftData()['content'],
            'a soft-deleted block is neither exported nor offered',
        );
    }

    public function testAFragmentLandsUnderTheExtensionKey(): void
    {
        $extension = new RecordingTransferExtension(fragment: ['rows' => ['s0.c0.b0' => 'de']]);
        $payload = (new ContentAreaExporter($this->makeResolver(), extensions: [$extension]))
            ->export($this->makeArea());

        $this->assertSame(
            ['acme/test' => ['rows' => ['s0.c0.b0' => 'de']]],
            $payload['extensions'],
        );
    }

    public function testAnEmptyFragmentWritesNoKeyAtAll(): void
    {
        $payload = (new ContentAreaExporter($this->makeResolver(), extensions: [new RecordingTransferExtension()]))
            ->export($this->makeArea());

        $this->assertArrayNotHasKey('extensions', $payload);
    }

    public function testAnExtensionCarriesBytesNoBlockDataMentions(): void
    {
        // The bug this guards: the payload's `assets` map read before the
        // extensions ran would drop a file only a translation references.
        $this->files['/uploads/only-in-a-row.png'] = 'row-bytes';
        $hash = hash('sha256', 'row-bytes');

        $extension = new RecordingTransferExtension(tokenize: ['value' => '/uploads/only-in-a-row.png']);
        $payload = (new ContentAreaExporter($this->makeResolver(), extensions: [$extension]))
            ->export($this->makeArea());

        $this->assertSame(
            ['value' => 'asset://' . $hash],
            $payload['extensions']['acme/test']['tokenized'],
        );
        $this->assertSame('row-bytes', base64_decode($payload['assets'][$hash]['data'], true));
    }

    public function testImportHandsTheBlocksItBuiltAndRewritesTheExtensionsAssets(): void
    {
        $binary = 'row-bytes';
        $hash = hash('sha256', $binary);
        $resolver = $this->makeResolver();

        $extension = new RecordingTransferExtension();
        $payload = [
            'format' => ContentAreaExporter::FORMAT,
            'contentArea' => ['sections' => [[
                'layout' => Section::LAYOUT_FULL,
                'columns' => [['preset' => 'col-12', 'blocks' => [
                    ['ref' => 's0.c0.b0', 'type' => 'text', 'data' => ['content' => 'kept']],
                    ['ref' => 's0.c0.b1', 'type' => 'countdown', 'data' => []],
                ]]],
            ]]],
            'assets' => [$hash => ['mimeType' => 'image/png', 'extension' => 'png', 'data' => base64_encode($binary)]],
            'extensions' => ['acme/test' => ['rewrite' => ['src' => 'asset://' . $hash]]],
        ];

        $this->importer($resolver, $extension)->import(new ContentArea(), $payload);

        $this->assertSame(1, $extension->importCalls);
        $this->assertSame(['s0.c0.b0'], array_keys($extension->importedBlocks));
        $this->assertSame(
            ['src' => '/uploads/imported.png'],
            $extension->rewritten,
            'the extension shares the importer’s asset map',
        );
    }

    public function testAnExtensionIsSilentWhenThePayloadDoesNotCarryItsKey(): void
    {
        $extension = new RecordingTransferExtension();
        $payload = [
            'format' => ContentAreaExporter::FORMAT,
            'contentArea' => ['sections' => []],
            'extensions' => ['someone/else' => ['rows' => []]],
        ];

        $this->importer($this->makeResolver(), $extension)->import(new ContentArea(), $payload);

        $this->assertSame(0, $extension->importCalls);
    }

    public function testRoundTripCarriesAFragmentBackToTheSameBlock(): void
    {
        $source = $this->makeArea();
        $resolver = $this->makeResolver();

        $exportSide = new RecordingTransferExtension(fragment: ['rows' => ['s1.c0.b0' => 'Bonjour']]);
        $payload = (new ContentAreaExporter($resolver, extensions: [$exportSide]))->export($source);

        $importSide = new RecordingTransferExtension();
        $target = new ContentArea();
        $this->importer($resolver, $importSide)->import($target, $payload);

        $ref = array_key_first(array_filter(
            $importSide->importedBlocks,
            fn (Block $block) => $block->getDraftData() === ['content' => 'block 1'],
        ));
        $this->assertSame('s1.c0.b0', $ref, 'the ref addresses the same block on both sides');
        $this->assertSame(['rows' => ['s1.c0.b0' => 'Bonjour']], $importSide->importedFragment);
    }
}

/**
 * Records what the seam hands it, and optionally contributes a fragment, a
 * value to tokenize, or both.
 */
final class RecordingTransferExtension implements ContentAreaTransferExtensionInterface
{
    /** @var array<string, Block> */
    public array $exportedBlocks = [];

    /** @var array<string, Block> */
    public array $importedBlocks = [];

    /** @var array<string, mixed>|null */
    public ?array $importedFragment = null;

    /** @var array<string, mixed>|null */
    public ?array $rewritten = null;

    public int $importCalls = 0;

    /**
     * @param array<string, mixed> $fragment
     * @param array<string, mixed> $tokenize
     */
    public function __construct(
        private readonly array $fragment = [],
        private readonly array $tokenize = [],
    ) {
    }

    public function key(): string
    {
        return 'acme/test';
    }

    public function export(ContentArea $area, array $blocks, AssetTokenizer $assets): array
    {
        $this->exportedBlocks = $blocks;
        $fragment = $this->fragment;

        if ($this->tokenize !== []) {
            $fragment['tokenized'] = $assets->tokenize($this->tokenize);
        }

        return $fragment;
    }

    public function import(ContentArea $area, array $blocks, array $fragment, AssetRewriter $assets): void
    {
        ++$this->importCalls;
        $this->importedBlocks = $blocks;
        $this->importedFragment = $fragment;

        if (isset($fragment['rewrite'])) {
            /** @var array<string, mixed> $rewritten */
            $rewritten = $assets->rewrite($fragment['rewrite']);
            $this->rewritten = $rewritten;
        }
    }
}
