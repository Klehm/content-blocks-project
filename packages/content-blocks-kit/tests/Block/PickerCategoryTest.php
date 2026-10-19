<?php

declare(strict_types=1);

namespace ContentBlocks\Kit\Tests\Block;

use ContentBlocks\BlockType\AsContentBlock;
use ContentBlocks\BlockType\BlockCategoryRegistry;
use ContentBlocks\Kit\ContentBlocksKitBundle;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Every kit block sits in a built-in picker group: an unknown one would stop
 * the host's cache:clear, and none of them belongs in "other".
 */
final class PickerCategoryTest extends TestCase
{
    /** @param class-string $class */
    #[DataProvider('blocks')]
    public function testTheBlockDeclaresABuiltInCategory(string $type, string $class): void
    {
        $attributes = (new \ReflectionClass($class))->getAttributes(AsContentBlock::class);
        $this->assertCount(1, $attributes, $type);

        $category = $attributes[0]->newInstance()->category;
        $this->assertNotNull($category, $type);
        $this->assertArrayHasKey($category, BlockCategoryRegistry::BUILT_IN, $type);
        $this->assertNotSame(BlockCategoryRegistry::OTHER, $category, $type);
    }

    /** @return iterable<string, array{string, class-string}> */
    public static function blocks(): iterable
    {
        foreach (ContentBlocksKitBundle::BLOCKS as $type => $class) {
            yield $type => [$type, $class];
        }
    }
}
