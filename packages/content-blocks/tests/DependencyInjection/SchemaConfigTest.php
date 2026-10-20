<?php

declare(strict_types=1);

namespace ContentBlocks\Tests\DependencyInjection;

use ContentBlocks\ContentBlocksBundle;
use ContentBlocks\Palette\ConfigColorPaletteProvider;
use ContentBlocks\Section\ConfigSectionStyleProvider;
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
 * The `content_blocks.schema` node, and the `role` a palette entry and a
 * section style carry into the content schema.
 */
final class SchemaConfigTest extends TestCase
{
    public function testTheDefaultsCarryNoPurposeAndThreeExamples(): void
    {
        $container = $this->build([]);

        $this->assertSame([], $container->getParameter('content_blocks.schema.block_purposes'));
        $this->assertSame(3, $container->getParameter('content_blocks.schema.examples'));
    }

    public function testPurposesAndExamplesReachTheirParameters(): void
    {
        $container = $this->build(['schema' => [
            'block_purposes' => ['card' => 'Our three plans.'],
            'examples' => 0,
        ]]);

        $this->assertSame(['card' => 'Our three plans.'], $container->getParameter('content_blocks.schema.block_purposes'));
        $this->assertSame(0, $container->getParameter('content_blocks.schema.examples'));
    }

    public function testANegativeExampleCountIsRefused(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        $this->processConfig(['schema' => ['examples' => -1]]);
    }

    public function testAPaletteEntryCarriesItsRoleToTheProvider(): void
    {
        $container = $this->build(['palette' => [
            ['label' => 'Brand', 'color' => '#eb0540', 'role' => 'Calls to action only.'],
            ['label' => 'Ink', 'color' => '#111827'],
        ]]);

        $palette = $container->getParameterBag()->resolveValue(
            $container->getDefinition(ConfigColorPaletteProvider::class)->getArgument(0),
        );
        $colors = iterator_to_array((new ConfigColorPaletteProvider($palette))->getColors(), false);

        $this->assertSame('Calls to action only.', $colors[0]->role);
        $this->assertNull($colors[1]->role);
    }

    public function testASectionStyleCarriesItsRoleToTheProvider(): void
    {
        $container = $this->build(['section_styles' => [
            ['name' => 'dark', 'label' => 'Dark', 'role' => 'A dark highlighted band.'],
        ]]);

        $styles = $container->getParameterBag()->resolveValue(
            $container->getDefinition(ConfigSectionStyleProvider::class)->getArgument(0),
        );

        $this->assertSame('A dark highlighted band.', (new ConfigSectionStyleProvider($styles))->getStyles()[0]->role);
    }

    /**
     * @param array<string, mixed> $config
     */
    private function build(array $config): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.project_dir', \dirname(__DIR__, 2));
        $instanceof = [];

        $configDir = \dirname(__DIR__, 2) . '/config';
        $loader = new PhpFileLoader($container, new FileLocator($configDir));

        (new ContentBlocksBundle())->loadExtension(
            $this->processConfig($config),
            new ContainerConfigurator($container, $loader, $instanceof, $configDir, 'services.php'),
            $container,
        );

        return $container;
    }

    /**
     * Runs the raw array through the bundle's own definition, so this test sees
     * exactly what Symfony would hand loadExtension() — defaults filled in and
     * invalid values rejected.
     *
     * @param array<string, mixed> $config
     *
     * @return array<string, mixed>
     */
    private function processConfig(array $config): array
    {
        $configDir = \dirname(__DIR__, 2) . '/config';
        $treeBuilder = new TreeBuilder('content_blocks');
        // configure() never calls import(), so the loader is only here to
        // satisfy the signature.
        $loader = new DefinitionFileLoader($treeBuilder, new FileLocator($configDir));

        (new ContentBlocksBundle())->configure(
            new DefinitionConfigurator($treeBuilder, $loader, $configDir, 'services.php'),
        );

        return (new Processor())->process($treeBuilder->buildTree(), [$config]);
    }
}
