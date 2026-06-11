<?php

declare(strict_types=1);

namespace ContentBlocks\Tests\Service;

use ContentBlocks\Asset\AssetResolverInterface;
use ContentBlocks\Entity\ContentArea;
use ContentBlocks\Entity\Section;
use ContentBlocks\Service\ContentAreaExporter;
use ContentBlocks\Service\ContentAreaImporter;
use PHPUnit\Framework\TestCase;

final class ContentAreaImporterTest extends TestCase
{
    /** @var array<int, string> Binaries handed to store(), in call order. */
    private array $stored = [];

    private function makeResolver(): AssetResolverInterface
    {
        $this->stored = [];
        $resolver = $this->createMock(AssetResolverInterface::class);
        $resolver->method('store')->willReturnCallback(function (string $binary, string $extension): string {
            $this->stored[] = $binary;

            return sprintf('/uploads/imported-%d.%s', \count($this->stored), $extension);
        });

        return $resolver;
    }

    private function makePayload(array $sections = [], array $assets = []): array
    {
        return [
            'format' => ContentAreaExporter::FORMAT,
            'contentArea' => ['sections' => $sections],
            'assets' => $assets,
        ];
    }

    public function testImportRejectsAnUnknownFormat(): void
    {
        $importer = new ContentAreaImporter($this->makeResolver());

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported format');
        $importer->import(new ContentArea(), ['format' => 'something/v9']);
    }

    public function testImportRejectsAMissingSectionsKey(): void
    {
        $importer = new ContentAreaImporter($this->makeResolver());

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('contentArea.sections');
        $importer->import(new ContentArea(), ['format' => ContentAreaExporter::FORMAT]);
    }

    public function testImportSoftDeletesExistingSectionsAndAddsDrafts(): void
    {
        $target = new ContentArea();
        $existing = new Section();
        $existing->setLayout(Section::LAYOUT_FULL);
        $target->addSection($existing);

        $payload = $this->makePayload([
            [
                'layout' => Section::LAYOUT_TWO_COLS,
                'settings' => ['backgroundColor' => '#000'],
                'columns' => [
                    ['preset' => 'col-6', 'blocks' => [['type' => 'text', 'data' => ['content' => 'imported']]]],
                    ['preset' => 'col-6', 'blocks' => []],
                ],
            ],
        ]);

        $count = (new ContentAreaImporter($this->makeResolver()))->import($target, $payload);

        $this->assertSame(1, $count);
        $this->assertTrue($existing->isDeleted());

        $imported = $target->getSections()[1];
        $this->assertSame(Section::LAYOUT_TWO_COLS, $imported->getLayout());
        $this->assertSame(['backgroundColor' => '#000'], $imported->getDraftSettings());
        $this->assertSame(0, $imported->getPreviewPosition());
        $this->assertCount(2, $imported->getColumns());

        $block = $imported->getColumns()[0]->getBlocks()[0];
        $this->assertSame('text', $block->getType());
        $this->assertSame(['content' => 'imported'], $block->getDraftData());
        // Never-published draft: publish commits it, discard drops it.
        $this->assertNull($block->getPublishedData());
    }

    public function testImportAssignsDensePreviewPositions(): void
    {
        $target = new ContentArea();
        $payload = $this->makePayload([
            ['layout' => Section::LAYOUT_FULL, 'columns' => []],
            ['layout' => Section::LAYOUT_FULL, 'columns' => []],
        ]);

        (new ContentAreaImporter($this->makeResolver()))->import($target, $payload);

        $this->assertSame([0, 1], array_map(
            fn (Section $s) => $s->getPreviewPosition(),
            $target->getSections()->toArray(),
        ));
    }

    public function testImportMaterializesAssetsAndRewritesTokens(): void
    {
        $binary = 'imported-bytes';
        $hash = hash('sha256', $binary);
        $payload = $this->makePayload(
            [[
                'layout' => Section::LAYOUT_FULL,
                'columns' => [[
                    'preset' => 'col-12',
                    'blocks' => [['type' => 'image', 'data' => ['src' => 'asset://' . $hash]]],
                ]],
            ]],
            [$hash => ['mimeType' => 'image/png', 'extension' => 'png', 'data' => base64_encode($binary)]],
        );

        $target = new ContentArea();
        (new ContentAreaImporter($this->makeResolver()))->import($target, $payload);

        $this->assertSame([$binary], $this->stored);
        $block = $target->getSections()[0]->getColumns()[0]->getBlocks()[0];
        $this->assertSame('/uploads/imported-1.png', $block->getDraftData()['src']);
    }

    public function testImportLeavesUnknownAssetTokensInPlace(): void
    {
        $payload = $this->makePayload([[
            'layout' => Section::LAYOUT_FULL,
            'columns' => [[
                'preset' => 'col-12',
                'blocks' => [['type' => 'image', 'data' => ['src' => 'asset://deadbeef']]],
            ]],
        ]]);

        $target = new ContentArea();
        (new ContentAreaImporter($this->makeResolver()))->import($target, $payload);

        $block = $target->getSections()[0]->getColumns()[0]->getBlocks()[0];
        // Unknown hash: surfaced as-is instead of silently dropped.
        $this->assertSame('asset://deadbeef', $block->getDraftData()['src']);
    }

    public function testImportRejectsMalformedAssetEntries(): void
    {
        $payload = $this->makePayload([], ['somehash' => ['data' => 42]]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Malformed asset entry');
        (new ContentAreaImporter($this->makeResolver()))->import(new ContentArea(), $payload);
    }

    public function testImportRejectsInvalidBase64AssetData(): void
    {
        $payload = $this->makePayload([], ['somehash' => ['extension' => 'png', 'data' => '%%%not-base64%%%']]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid base64');
        (new ContentAreaImporter($this->makeResolver()))->import(new ContentArea(), $payload);
    }

    public function testExportImportRoundTripPreservesTheTree(): void
    {
        // Build a source area, export it, import into a fresh target, and
        // compare a second export of the target: byte-identical content.
        $source = new ContentArea();
        $section = new Section();
        $section->setLayout(Section::LAYOUT_TWO_COLS);
        $section->setDraftSettings(['gap' => '2rem']);
        $section->setPreviewPosition(0);
        $source->addSection($section);
        foreach (['col-6', 'col-6'] as $i => $preset) {
            $column = new \ContentBlocks\Entity\Column();
            $column->setPreset($preset);
            $column->setPreviewPosition($i);
            $section->addColumn($column);
        }
        $block = new \ContentBlocks\Entity\Block();
        $block->setType('text');
        $block->setDraftData(['content' => 'round-trip']);
        $block->setPreviewPosition(0);
        $section->getColumns()[0]->addBlock($block);

        $neverAsset = $this->createMock(AssetResolverInterface::class);
        $neverAsset->method('isAssetPath')->willReturn(false);

        $exporter = new ContentAreaExporter($neverAsset);
        $exported = $exporter->export($source);

        $target = new ContentArea();
        (new ContentAreaImporter($neverAsset))->import($target, $exported);
        $reExported = $exporter->export($target);

        $this->assertSame($exported['contentArea'], $reExported['contentArea']);
    }
}
