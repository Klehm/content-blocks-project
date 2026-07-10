<?php

declare(strict_types=1);

namespace ContentBlocks\Kit\Tests;

use ContentBlocks\Kit\Block\AbstractKitBlock;
use ContentBlocks\Kit\Block\ButtonBlock;
use ContentBlocks\Kit\Block\GalleryBlock;
use ContentBlocks\Kit\Block\HtmlRawBlock;
use ContentBlocks\Kit\Block\TabsBlock;
use ContentBlocks\Kit\Block\TitleBlock;
use ContentBlocks\Kit\ContentBlocksKitBundle;
use PHPUnit\Framework\TestCase;

final class ContentBlocksKitBundleTest extends TestCase
{
    public function testEveryRegisteredBlockKeyMatchesItsGetType(): void
    {
        foreach (ContentBlocksKitBundle::BLOCKS as $type => $class) {
            $this->assertTrue(is_subclass_of($class, AbstractKitBlock::class), "$class must extend AbstractKitBlock");
            $this->assertSame($type, $class::getType(), "BLOCKS key '$type' must match {$class}::getType()");
        }
    }

    public function testEveryBlockClassOnDiskIsRegistered(): void
    {
        // Guards against adding a block file but forgetting the BLOCKS entry.
        $registered = array_values(ContentBlocksKitBundle::BLOCKS);
        foreach (glob(__DIR__ . '/../src/Block/*Block.php') ?: [] as $file) {
            $class = 'ContentBlocks\\Kit\\Block\\' . basename($file, '.php');
            if (!class_exists($class) || (new \ReflectionClass($class))->isAbstract()) {
                continue;
            }
            $this->assertContains($class, $registered, "$class exists but is not listed in ContentBlocksKitBundle::BLOCKS");
        }
    }

    public function testAllBlocksExceptDefaultDisabledAreEnabledByDefault(): void
    {
        $resolved = ContentBlocksKitBundle::resolveBlocks([]);

        $expectedCount = \count(ContentBlocksKitBundle::BLOCKS) - \count(ContentBlocksKitBundle::DEFAULT_DISABLED);
        $this->assertCount($expectedCount, $resolved);

        foreach (ContentBlocksKitBundle::BLOCKS as $type => $class) {
            if (\in_array($type, ContentBlocksKitBundle::DEFAULT_DISABLED, true)) {
                $this->assertArrayNotHasKey($class, $resolved, "'$type' is DEFAULT_DISABLED and must be off by default");
            } else {
                $this->assertArrayHasKey($class, $resolved);
            }
        }
    }

    public function testHtmlRawIsOffByDefaultButOptInReRegistersIt(): void
    {
        $this->assertArrayNotHasKey(HtmlRawBlock::class, ContentBlocksKitBundle::resolveBlocks([]));

        $optedIn = ContentBlocksKitBundle::resolveBlocks([
            'blocks' => ['html_raw' => ['enabled' => true]],
        ]);
        $this->assertArrayHasKey(HtmlRawBlock::class, $optedIn);
    }

    public function testDisablingABlockUnregistersIt(): void
    {
        $resolved = ContentBlocksKitBundle::resolveBlocks([
            'blocks' => ['tabs' => ['enabled' => false]],
        ]);

        $this->assertArrayNotHasKey(TabsBlock::class, $resolved);
        $this->assertArrayHasKey(TitleBlock::class, $resolved);
    }

    public function testOptionsMergeOverCodedDefaults(): void
    {
        $resolved = ContentBlocksKitBundle::resolveBlocks([
            'blocks' => ['title' => ['options' => ['foo' => 'bar']]],
        ]);

        // TitleBlock declares no coded options, so the host option lands as-is.
        $this->assertSame(['foo' => 'bar'], $resolved[TitleBlock::class]['options']);
    }

    public function testCodedOptionsSurviveWhenHostOverridesOnlySome(): void
    {
        // GalleryBlock codes max_columns=6; an unrelated host option merges in
        // without dropping the coded default.
        $resolved = ContentBlocksKitBundle::resolveBlocks([
            'blocks' => ['gallery' => ['options' => ['foo' => 'bar']]],
        ]);

        $this->assertSame(['max_columns' => 6, 'foo' => 'bar'], $resolved[GalleryBlock::class]['options']);
    }

    public function testChoicesAndDefaultsPassThroughRaw(): void
    {
        $resolved = ContentBlocksKitBundle::resolveBlocks([
            'blocks' => ['button' => [
                'choices' => ['variant' => ['primary', 'secondary']],
                'defaults' => ['align' => 'center'],
            ]],
        ]);

        $this->assertSame(['variant' => ['primary', 'secondary']], $resolved[ButtonBlock::class]['choices']);
        $this->assertSame(['align' => 'center'], $resolved[ButtonBlock::class]['defaults']);
    }

    public function testEnabledBlockAlwaysHasTheThreeConfigKeys(): void
    {
        // Every resolved block exposes options/choices/defaults so loadExtension
        // can wire all three constructor args unconditionally.
        foreach (ContentBlocksKitBundle::resolveBlocks([]) as $config) {
            $this->assertArrayHasKey('options', $config);
            $this->assertArrayHasKey('choices', $config);
            $this->assertArrayHasKey('defaults', $config);
        }
    }
}
