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
        'title' => ['text', 'tag'],
        'text' => ['content'],
        'rich_text' => ['content'],
        'image' => ['src', 'alt', 'width', 'height'],
        'button' => ['text', 'href', 'variant', 'size', 'align', 'fullWidth', 'newTab'],
        'list' => ['style', 'items'],
        'icon' => ['name', 'color', 'size', 'align'],
        'alert' => ['type', 'title', 'message'],
        'divider' => ['style', 'color'],
        'accordion' => ['exclusive', 'items'],
        'embed' => ['url', 'title'],
        'breadcrumb' => ['items'],
        'html_raw' => ['html'],
        'tabs' => ['tabs'],
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
}
