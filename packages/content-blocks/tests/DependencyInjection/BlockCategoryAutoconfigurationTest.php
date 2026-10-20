<?php

declare(strict_types=1);

namespace ContentBlocks\Tests\DependencyInjection;

use ContentBlocks\BlockType\BlockTypeRegistry;
use ContentBlocks\ContentBlocksBundle;
use ContentBlocks\Tests\Fixtures\BlockType\MediaFixtureBlock;
use ContentBlocks\Tests\Fixtures\BlockType\MediaSubclassFixtureBlock;
use ContentBlocks\Tests\Fixtures\BlockType\TypoCategoryFixtureBlock;
use ContentBlocks\Tests\Fixtures\BlockType\UncategorizedFixtureBlock;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;

/**
 * `#[AsContentBlock(category: …)]` alone, through autoconfiguration and
 * BlockTypeCompilerPass, reaches the registry.
 */
final class BlockCategoryAutoconfigurationTest extends TestCase
{
    public function testTheAttributesCategoryReachesTheRegistry(): void
    {
        $registry = $this->compileWith([], MediaFixtureBlock::class, UncategorizedFixtureBlock::class);

        $this->assertSame('media', $registry->getCategory('fixture_media'));
        $this->assertNull($registry->getCategory('fixture_plain'));
        // Priority still orders the registry: 20 before 10.
        $this->assertSame(['fixture_plain', 'fixture_media'], array_keys($registry->all()));
    }

    public function testASubclassKeepsItsParentsCategory(): void
    {
        $registry = $this->compileWith([], MediaSubclassFixtureBlock::class);

        $this->assertSame('media', $registry->getCategory('fixture_media_subclass'));
    }

    public function testAnUndeclaredCategoryStopsTheBuildWithTheNearestName(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('did you mean "media"');

        $this->compileWith([], TypoCategoryFixtureBlock::class);
    }

    public function testAHostCategoryIsKnownOnceDeclared(): void
    {
        $registry = $this->compileWith(['medai' => 'Typo, but declared'], TypoCategoryFixtureBlock::class);

        $this->assertSame('medai', $registry->getCategory('fixture_typo'));
    }

    /**
     * @param array<string, string> $hostCategories
     */
    private function compileWith(array $hostCategories, string ...$blockClasses): BlockTypeRegistry
    {
        $container = new ContainerBuilder();
        (new ContentBlocksBundle())->build($container);
        $container->setParameter(
            'content_blocks.picker.categories',
            \ContentBlocks\BlockType\BlockCategoryRegistry::resolve($hostCategories),
        );
        $container->setDefinition(
            BlockTypeRegistry::class,
            (new Definition(BlockTypeRegistry::class))->setPublic(true),
        );
        foreach ($blockClasses as $class) {
            $container->register($class, $class)->setAutoconfigured(true);
        }

        $container->compile();

        $registry = $container->get(BlockTypeRegistry::class);
        \assert($registry instanceof BlockTypeRegistry);

        return $registry;
    }
}
