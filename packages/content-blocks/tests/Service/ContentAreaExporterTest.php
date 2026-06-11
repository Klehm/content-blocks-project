<?php

declare(strict_types=1);

namespace ContentBlocks\Tests\Service;

use ContentBlocks\Asset\AssetResolverInterface;
use ContentBlocks\Entity\Block;
use ContentBlocks\Entity\Column;
use ContentBlocks\Entity\ContentArea;
use ContentBlocks\Entity\Section;
use ContentBlocks\Service\ContentAreaExporter;
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

    private function makeArea(): ContentArea
    {
        return new ContentArea();
    }

    private function makeSection(ContentArea $area, int $previewPosition = 0, ?array $draftSettings = null): Section
    {
        $section = new Section();
        $section->setLayout(Section::LAYOUT_FULL);
        $section->setPreviewPosition($previewPosition);
        if ($draftSettings !== null) {
            $section->setDraftSettings($draftSettings);
        }
        $area->addSection($section);

        return $section;
    }

    private function makeColumn(Section $section, int $previewPosition = 0): Column
    {
        $column = new Column();
        $column->setPreset('col-12');
        $column->setPreviewPosition($previewPosition);
        $section->addColumn($column);

        return $column;
    }

    private function makeBlock(Column $column, string $type = 'text', ?array $draft = null, ?array $published = null, int $previewPosition = 0): Block
    {
        $block = new Block();
        $block->setType($type);
        $block->setPreviewPosition($previewPosition);
        if ($draft !== null) {
            $block->setDraftData($draft);
        }
        if ($published !== null) {
            $block->setPublishedData($published);
        }
        $column->addBlock($block);

        return $block;
    }

    public function testExportProducesTheVersionedFormatWithTheFullTree(): void
    {
        $area = $this->makeArea();
        $section = $this->makeSection($area, draftSettings: ['backgroundColor' => '#fff']);
        $column = $this->makeColumn($section);
        $this->makeBlock($column, 'text', draft: ['content' => 'hello']);

        $payload = (new ContentAreaExporter($this->makeResolver()))->export($area);

        $this->assertSame(ContentAreaExporter::FORMAT, $payload['format']);
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
        $area = $this->makeArea();
        $column = $this->makeColumn($this->makeSection($area));
        $this->makeBlock($column, draft: ['content' => 'draft'], published: ['content' => 'published']);

        $payload = (new ContentAreaExporter($this->makeResolver()))->export($area);

        $this->assertSame(
            ['content' => 'draft'],
            $payload['contentArea']['sections'][0]['columns'][0]['blocks'][0]['data'],
        );
    }

    public function testExportSkipsDeletedEntriesAndOrdersByPreviewPosition(): void
    {
        $area = $this->makeArea();
        $second = $this->makeSection($area, previewPosition: 1, draftSettings: ['marker' => 'second']);
        $first = $this->makeSection($area, previewPosition: 0, draftSettings: ['marker' => 'first']);
        $dead = $this->makeSection($area, previewPosition: 2);
        $dead->setDeleted(true);

        $column = $this->makeColumn($first);
        $deadBlock = $this->makeBlock($column, draft: ['content' => 'dead'], previewPosition: 0);
        $deadBlock->setDeleted(true);
        $this->makeBlock($column, draft: ['content' => 'alive'], previewPosition: 1);

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

        $area = $this->makeArea();
        $column = $this->makeColumn($this->makeSection($area));
        $this->makeBlock($column, 'image', draft: ['src' => '/uploads/a.png'], previewPosition: 0);
        $this->makeBlock($column, 'image', draft: ['src' => '/uploads/b.png'], previewPosition: 1);

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
        $area = $this->makeArea();
        $column = $this->makeColumn($this->makeSection($area));
        $this->makeBlock($column, 'image', draft: ['src' => '/uploads/gone.png']);

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

        $area = $this->makeArea();
        $column = $this->makeColumn($this->makeSection($area));
        $this->makeBlock($column, 'tabs', draft: [
            'tabs' => [
                ['title' => 'One', 'image' => '/uploads/deep.jpg'],
            ],
        ]);

        $payload = (new ContentAreaExporter($resolver))->export($area);

        $data = $payload['contentArea']['sections'][0]['columns'][0]['blocks'][0]['data'];
        $this->assertSame('asset://' . $hash, $data['tabs'][0]['image']);
    }
}
