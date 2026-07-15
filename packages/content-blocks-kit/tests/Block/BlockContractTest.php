<?php

declare(strict_types=1);

namespace ContentBlocks\Kit\Tests\Block;

use ContentBlocks\Kit\ContentBlocksKitBundle;
use PHPUnit\Framework\TestCase;

/**
 * Contract checks that hold for every kit block: a non-empty type, a label, an
 * inline-SVG icon, and a getDefaultData() whose keys are a stable, documented
 * shape (guards against a rename drifting the stored data schema).
 */
final class BlockContractTest extends TestCase
{
    /**
     * Expected default-data keys per block type. Kept explicit so a field
     * rename must be reflected here on purpose.
     *
     * @return array<string, list<string>>
     */
    private const EXPECTED_KEYS = [
        'title' => ['text', 'size', 'tag', 'color'],
        'text' => ['content', 'color'],
        'rich_text' => ['content'],
        'image' => ['src', 'alt', 'size', 'customWidth', 'customHeightAuto', 'customHeight', 'fit', 'align', 'url', 'caption', 'borderRadius'],
        'gallery' => ['layout', 'columns', 'fit', 'borderRadius', 'items'],
        'button' => ['text', 'url', 'variant', 'size', 'align', 'fullWidth', 'newTab'],
        'card' => ['layout', 'columns', 'items'],
        'list' => ['style', 'items'],
        'icon' => ['name', 'color', 'size', 'align'],
        'alert' => ['type', 'title', 'content'],
        'divider' => ['style', 'color'],
        'accordion' => ['exclusive', 'items'],
        'table' => ['striped', 'columns', 'rows'],
        'embed' => ['url', 'title'],
        'breadcrumb' => ['items'],
        'html_raw' => ['html'],
        'tabs' => ['items'],
    ];

    public function testEveryBlockHasTypeLabelAndIcon(): void
    {
        foreach (ContentBlocksKitBundle::BLOCKS as $type => $class) {
            $this->assertNotSame('', $class::getType());
            $this->assertNotNull($class::getLabel());
            $icon = $class::getIcon();
            $this->assertNotNull($icon, "$type should ship an icon");
            $this->assertStringContainsString('<svg', $icon);
        }
    }

    public function testDefaultDataKeysAreStable(): void
    {
        foreach (ContentBlocksKitBundle::BLOCKS as $type => $class) {
            $block = new $class();
            $keys = array_keys($block->getDefaultData());
            sort($keys);
            $expected = self::EXPECTED_KEYS[$type];
            sort($expected);
            $this->assertSame($expected, $keys, "Default-data keys drifted for '$type'");
        }
    }

    /**
     * Every kit view is self-contained static markup (or CSS-only, or a Stimulus
     * controller that auto-connects on DOM insertion), so it may hot-reload in
     * place. The one exception is html_raw: its `{{ html|raw }}` can carry inline
     * <script> tags, which a hot innerHTML swap would NOT execute — only a full
     * iframe reload runs them — so it must decline hot reload.
     *
     * Pinned per type so adding a JS-dependent block (or forgetting the opt-in on
     * a static one) fails loudly rather than silently degrading the preview.
     *
     * @return array<string, bool>
     */
    private const EXPECTED_HOT_RELOAD = [
        'title' => true,
        'text' => true,
        'rich_text' => true,
        'image' => true,
        'gallery' => true,
        'button' => true,
        'card' => true,
        'list' => true,
        'icon' => true,
        'alert' => true,
        'divider' => true,
        'accordion' => true,
        'table' => true,
        'embed' => true,
        'breadcrumb' => true,
        'html_raw' => false,
        'tabs' => true,
    ];

    public function testPreviewHotReloadIsDeclaredPerType(): void
    {
        foreach (ContentBlocksKitBundle::BLOCKS as $type => $class) {
            $this->assertArrayHasKey($type, self::EXPECTED_HOT_RELOAD, "No hot-reload expectation for '$type'");
            $this->assertSame(
                self::EXPECTED_HOT_RELOAD[$type],
                (new $class())->supportsPreviewHotReload(),
                "supportsPreviewHotReload() drifted for '$type'",
            );
        }
    }
}
