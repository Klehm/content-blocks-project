<?php

declare(strict_types=1);

namespace ContentBlocks\Tests\Transfer;

use ContentBlocks\Asset\AssetResolverInterface;
use ContentBlocks\Entity\Section;
use ContentBlocks\Test\ColumnBuilder;
use ContentBlocks\Test\ContentAreaBuilder;
use ContentBlocks\Test\SectionBuilder;
use ContentBlocks\Transfer\ContentAreaExporter;
use PHPUnit\Framework\TestCase;

final class ContentAreaExporterTest extends TestCase
{
    /**
     * Resolver double backed by an in-memory map: path => binary. Paths
     * starting with /uploads/ are recognized as assets.
     *
     * @param array<string, string> $files
     */
    private function makeResolver(array $files = []): AssetResolverInterface
    {
        $resolver = $this->createMock(AssetResolverInterface::class);
        $resolver->method('isAssetPath')->willReturnCallback(
            fn (string $value) => str_starts_with($value, '/uploads/'),
        );
        $resolver->method('read')->willReturnCallback(
            fn (string $path) => $files[$path] ?? null,
        );

        return $resolver;
    }

    /**
     * The exporter reads the draft side in preference to the published one, so
     * these fixtures are built as drafts unless a test is about that choice.
     */
    private function draftArea(): ContentAreaBuilder
    {
        return ContentAreaBuilder::create()->draft();
    }

    /**
     * The shape most tests here need: one section, one column, one block whose
     * payload is the thing under test.
     *
     * @param array<string, mixed> $data
     */
    private function areaWithOneBlock(string $type, array $data): \ContentBlocks\Entity\ContentArea
    {
        return $this->draftArea()
            ->section(fn (SectionBuilder $s) => $s
                ->column(fn (ColumnBuilder $c) => $c->block($type, $data)))
            ->build();
    }

    public function testExportProducesTheVersionedFormatWithTheFullTree(): void
    {
        $area = $this->draftArea()
            ->section(fn (SectionBuilder $s) => $s
                ->layout(Section::LAYOUT_FULL)
                ->settings(['backgroundColor' => '#fff'])
                ->column(fn (ColumnBuilder $c) => $c
                    ->preset('col-12')
                    ->block('text', ['content' => 'hello'])))
            ->build();

        $payload = (new ContentAreaExporter($this->makeResolver()))->export($area);

        $this->assertSame(ContentAreaExporter::FORMAT, $payload['format']);
        $this->assertSame(1, $payload['contentVersion'], 'default when the host configures none');
        $this->assertArrayHasKey('exportedAt', $payload);
        $sections = $payload['contentArea']['sections'];
        $this->assertCount(1, $sections);
        $this->assertSame(Section::LAYOUT_FULL, $sections[0]['layout']);
        $this->assertSame(['backgroundColor' => '#fff'], $sections[0]['settings']);
        $this->assertSame('col-12', $sections[0]['columns'][0]['preset']);
        $this->assertSame(
            ['type' => 'text', 'data' => ['content' => 'hello']],
            $sections[0]['columns'][0]['blocks'][0],
        );
        $this->assertSame([], $payload['assets']);
    }

    public function testExportPrefersDraftDataOverPublished(): void
    {
        $area = ContentAreaBuilder::create()
            ->section(fn (SectionBuilder $s) => $s
                ->column(fn (ColumnBuilder $c) => $c->block(
                    'text',
                    configure: fn ($b) => $b
                        ->draftData(['content' => 'draft'])
                        ->publishedData(['content' => 'published']),
                )))
            ->build();

        $payload = (new ContentAreaExporter($this->makeResolver()))->export($area);

        $this->assertSame(
            ['content' => 'draft'],
            $payload['contentArea']['sections'][0]['columns'][0]['blocks'][0]['data'],
        );
    }

    public function testExportSkipsDeletedEntriesAndOrdersByPreviewPosition(): void
    {
        // Added out of collection order on purpose: the export is ordered by
        // previewPosition, not by the order sections were attached.
        $area = $this->draftArea()
            ->section(fn (SectionBuilder $s) => $s->position(1)->settings(['marker' => 'second']))
            ->section(fn (SectionBuilder $s) => $s
                ->position(0)
                ->settings(['marker' => 'first'])
                ->column(fn (ColumnBuilder $c) => $c
                    ->block('text', ['content' => 'dead'], fn ($b) => $b->deleted())
                    ->block('text', ['content' => 'alive'])))
            ->section(fn (SectionBuilder $s) => $s->position(2)->deleted())
            ->build();

        $payload = (new ContentAreaExporter($this->makeResolver()))->export($area);

        $sections = $payload['contentArea']['sections'];
        $this->assertCount(2, $sections);
        $this->assertSame('first', $sections[0]['settings']['marker']);
        $this->assertSame('second', $sections[1]['settings']['marker']);
        $blocks = $sections[0]['columns'][0]['blocks'];
        $this->assertCount(1, $blocks);
        $this->assertSame('alive', $blocks[0]['data']['content']);
    }

    public function testExportEmbedsAssetsAsBase64AndDeduplicatesByHash(): void
    {
        $binary = 'fake-image-bytes';
        $hash = hash('sha256', $binary);
        $resolver = $this->makeResolver(['/uploads/a.png' => $binary, '/uploads/b.png' => $binary]);

        $area = $this->draftArea()
            ->section(fn (SectionBuilder $s) => $s
                ->column(fn (ColumnBuilder $c) => $c
                    ->block('image', ['src' => '/uploads/a.png'])
                    ->block('image', ['src' => '/uploads/b.png'])))
            ->build();

        $payload = (new ContentAreaExporter($resolver))->export($area);

        $blocks = $payload['contentArea']['sections'][0]['columns'][0]['blocks'];
        // Both paths point at the same binary → one shared asset entry.
        $this->assertSame('asset://' . $hash, $blocks[0]['data']['src']);
        $this->assertSame('asset://' . $hash, $blocks[1]['data']['src']);
        $this->assertCount(1, $payload['assets']);
        $this->assertSame(base64_encode($binary), $payload['assets'][$hash]['data']);
        $this->assertSame('png', $payload['assets'][$hash]['extension']);
    }

    public function testExportKeepsThePathWhenTheAssetIsMissingOnDisk(): void
    {
        $area = $this->areaWithOneBlock('image', ['src' => '/uploads/gone.png']);

        $payload = (new ContentAreaExporter($this->makeResolver()))->export($area);

        $this->assertSame(
            '/uploads/gone.png',
            $payload['contentArea']['sections'][0]['columns'][0]['blocks'][0]['data']['src'],
        );
        $this->assertSame([], $payload['assets']);
    }

    public function testExportWalksNestedDataStructures(): void
    {
        $binary = 'nested-bytes';
        $hash = hash('sha256', $binary);
        $resolver = $this->makeResolver(['/uploads/deep.jpg' => $binary]);

        $area = $this->areaWithOneBlock('tabs', [
            'tabs' => [
                ['title' => 'One', 'image' => '/uploads/deep.jpg'],
            ],
        ]);

        $payload = (new ContentAreaExporter($resolver))->export($area);

        $data = $payload['contentArea']['sections'][0]['columns'][0]['blocks'][0]['data'];
        $this->assertSame('asset://' . $hash, $data['tabs'][0]['image']);
    }
}
