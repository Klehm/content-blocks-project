<?php

declare(strict_types=1);

namespace ContentBlocks\Kit\Tests;

use ContentBlocks\Kit\Block\AbstractKitBlock;
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

    public function testAllBlocksEnabledByDefault(): void
    {
        $resolved = ContentBlocksKitBundle::resolveBlocks([]);

        $this->assertCount(\count(ContentBlocksKitBundle::BLOCKS), $resolved);
        foreach (ContentBlocksKitBundle::BLOCKS as $class) {
            $this->assertArrayHasKey($class, $resolved);
        }
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

        // TitleBlock declares no defaults, so the host option lands as-is.
        $this->assertSame(['foo' => 'bar'], $resolved[TitleBlock::class]);
    }
}
