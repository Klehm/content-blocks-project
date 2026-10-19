<?php

declare(strict_types=1);

namespace ContentBlocks\Tests\DependencyInjection;

use ContentBlocks\ContentBlocksBundle;
use ContentBlocks\PublicAsset\CssLayer;
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
 * `content_blocks.css_layer`: config → parameter → the `CssLayer` service the
 * core and kit asset routes read.
 */
final class CssLayerConfigTest extends TestCase
{
    // Opt-in: a host that sets nothing is served unlayered CSS, as before.
    public function testItDefaultsToNoLayer(): void
    {
        $container = $this->build([]);

        $this->assertNull($container->getParameter('content_blocks.css_layer'));
        $this->assertNull($this->layer($container)->name);
    }

    public function testTheConfiguredLayerReachesTheService(): void
    {
        $container = $this->build(['css_layer' => 'vendor.content-blocks']);

        $this->assertSame('vendor.content-blocks', $this->layer($container)->name);
    }

    public function testANameThatIsNotALayerNameIsRefusedAtBoot(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->build(['css_layer' => 'content blocks']);
    }

    private function layer(ContainerBuilder $container): CssLayer
    {
        $argument = $container->getDefinition(CssLayer::class)->getArgument(0);

        return new CssLayer($container->getParameterBag()->resolveValue($argument));
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

        $treeBuilder = new TreeBuilder('content_blocks');
        $bundle = new ContentBlocksBundle();
        $bundle->configure(new DefinitionConfigurator(
            $treeBuilder,
            new DefinitionFileLoader($treeBuilder, new FileLocator($configDir)),
            $configDir,
            'services.php',
        ));
        $bundle->loadExtension(
            (new Processor())->process($treeBuilder->buildTree(), [$config]),
            new ContainerConfigurator($container, $loader, $instanceof, $configDir, 'services.php'),
            $container,
        );

        return $container;
    }
}
