<?php

declare(strict_types=1);

namespace ContentBlocks\Tests\DependencyInjection;

use ContentBlocks\ContentBlocksBundle;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Loader\DefinitionFileLoader;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;

/**
 * `content_blocks.picker`: validated at cache:clear, resolved over the
 * built-in categories into the parameters the registry is built from.
 */
final class PickerConfigTest extends TestCase
{
    public function testTheBuiltInsAreResolvedWhenNothingIsConfigured(): void
    {
        $container = $this->build([]);

        $this->assertSame(
            ['text', 'media', 'layout', 'actions', 'advanced', 'other'],
            array_keys((array) $container->getParameter('content_blocks.picker.categories')),
        );
        $this->assertSame([], $container->getParameter('content_blocks.picker.blocks'));
    }

    public function testAHostCategoryAndAnAssignmentComeThrough(): void
    {
        $container = $this->build(['picker' => [
            'categories' => ['shop' => 'Shop', 'text' => null],
            'blocks' => ['embed' => 'shop'],
        ]]);

        $categories = (array) $container->getParameter('content_blocks.picker.categories');
        $this->assertSame(['shop', 'text'], \array_slice(array_keys($categories), 0, 2));
        $this->assertSame('cb.picker.category.text', $categories['text']);
        $this->assertSame(['embed' => 'shop'], $container->getParameter('content_blocks.picker.blocks'));
    }

    public function testANewCategoryWithoutALabelIsRefused(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('needs a label');

        $this->build(['picker' => ['categories' => ['shop' => null]]]);
    }

    public function testANameThatIsNotSnakeCaseIsRefused(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('snake_case');

        $this->build(['picker' => ['categories' => ['Shop Items' => 'Shop']]]);
    }

    public function testAnAssignmentToAnUndeclaredCategoryIsRefused(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('not declared');

        $this->build(['picker' => ['blocks' => ['embed' => 'shop']]]);
    }

    /** @param array<string, mixed> $config */
    private function build(array $config): ContainerBuilder
    {
        $configDir = \dirname(__DIR__, 2) . '/config';
        $treeBuilder = new TreeBuilder('content_blocks');
        (new ContentBlocksBundle())->configure(new DefinitionConfigurator(
            $treeBuilder,
            new DefinitionFileLoader($treeBuilder, new FileLocator($configDir)),
            $configDir,
            'services.php',
        ));
        $processed = (new Processor())->process($treeBuilder->buildTree(), [$config]);

        $container = new ContainerBuilder();
        $container->setParameter('kernel.project_dir', \dirname(__DIR__, 2));
        $instanceof = [];
        $loader = new PhpFileLoader($container, new FileLocator($configDir));
        (new ContentBlocksBundle())->loadExtension(
            $processed,
            new ContainerConfigurator($container, $loader, $instanceof, $configDir, 'services.php'),
            $container,
        );

        return $container;
    }
}
