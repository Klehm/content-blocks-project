<?php

declare(strict_types=1);

namespace ContentBlocks\Tests\DependencyInjection;

use ContentBlocks\ContentBlocksBundle;
use ContentBlocks\Palette\ConfigColorPaletteProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\Config\Definition\Loader\DefinitionFileLoader;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;

final class PaletteConfigTest extends TestCase
{
    public function testAnEntryCarriesItsOptionalSwatchToTheProvider(): void
    {
        $container = $this->build(['palette' => [
            ['label' => 'Brand', 'color' => 'var(--brand)', 'swatch' => '#101828'],
            ['label' => 'Red', 'color' => '#eb0540'],
        ]]);

        $palette = $container->getParameterBag()->resolveValue(
            $container->getDefinition(ConfigColorPaletteProvider::class)->getArgument(0),
        );
        $colors = iterator_to_array((new ConfigColorPaletteProvider($palette))->getColors(), false);

        $this->assertSame('#101828', $colors[0]->swatch);
        $this->assertNull($colors[1]->swatch);
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
