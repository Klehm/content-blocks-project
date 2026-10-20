<?php

declare(strict_types=1);

namespace ContentBlocks\Tests\BlockType;

use ContentBlocks\BlockType\BlockCategoryRegistry;
use PHPUnit\Framework\TestCase;

final class BlockCategoryRegistryTest extends TestCase
{
    public function testTheBuiltInsInTheirOrderWhenNothingIsConfigured(): void
    {
        $this->assertSame(
            ['text', 'media', 'layout', 'actions', 'advanced', 'other'],
            array_keys((new BlockCategoryRegistry())->all()),
        );
    }

    public function testListedCategoriesComeFirstAndANullLabelKeepsTheBuiltIn(): void
    {
        $resolved = BlockCategoryRegistry::resolve([
            'shop' => 'Shop',
            'media' => 'Visuals',
            'text' => null,
        ]);

        $this->assertSame(
            ['shop', 'media', 'text', 'layout', 'actions', 'advanced', 'other'],
            array_keys($resolved),
        );
        $this->assertSame('Visuals', $resolved['media']);
        $this->assertSame('cb.picker.category.text', $resolved['text']);
    }

    public function testANewCategoryWithoutALabelIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        BlockCategoryRegistry::resolve(['shop' => null]);
    }

    public function testTheAssignmentWinsOverTheDeclaredCategory(): void
    {
        $categories = new BlockCategoryRegistry(null, ['embed' => 'advanced']);

        $this->assertSame('advanced', $categories->categoryOf('embed', 'media'));
        $this->assertSame('media', $categories->categoryOf('image', 'media'));
    }

    public function testNoCategoryOrAnUnknownOneLandsInOther(): void
    {
        $categories = new BlockCategoryRegistry();

        $this->assertSame('other', $categories->categoryOf('custom', null));
        $this->assertSame('other', $categories->categoryOf('custom', 'nowhere'));
    }

    public function testAnAssignmentForAnUnregisteredTypeIsIgnored(): void
    {
        $categories = new BlockCategoryRegistry(null, ['html_raw' => 'advanced']);

        $categories->assertAssignments(['title', 'image']);

        $this->addToAssertionCount(1);
    }

    public function testANearMissAssignmentIsRefused(): void
    {
        $categories = new BlockCategoryRegistry(null, ['imgae' => 'media']);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('did you mean "image"');

        $categories->assertAssignments(['title', 'image']);
    }
}
