<?php

declare(strict_types=1);

namespace ContentBlocks\Tests\DependencyInjection;

use ContentBlocks\ContentBlocksBundle;
use ContentBlocks\Rendering\EncoreEntrypointResolver;
use ContentBlocks\Rendering\EntrypointResolverInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\Config\Definition\Loader\DefinitionFileLoader;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;

/**
 * With WebpackEncoreBundle installed (a dev dependency here), its adapter
 * answers for block `entries`; a host alias of the interface replaces it.
 */
final class EntrypointResolverWiringTest extends TestCase
{
    public function testTheEncoreAdapterIsTheDefaultResolver(): void
    {
        $container = $this->build();

        $this->assertTrue($container->hasDefinition(EncoreEntrypointResolver::class));
        $this->assertSame(
            EncoreEntrypointResolver::class,
            (string) $container->getAlias(EntrypointResolverInterface::class),
        );
    }

    private function build(): ContainerBuilder
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
            (new Processor())->process($treeBuilder->buildTree(), [[]]),
            new ContainerConfigurator($container, $loader, $instanceof, $configDir, 'services.php'),
            $container,
        );

        return $container;
    }
}
