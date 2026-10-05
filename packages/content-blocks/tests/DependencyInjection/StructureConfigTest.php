<?php

declare(strict_types=1);

namespace ContentBlocks\Tests\DependencyInjection;

use ContentBlocks\Builder\BuilderStructure;
use ContentBlocks\Builder\BuilderStructureResolverInterface;
use ContentBlocks\Builder\ConfiguredBuilderStructureResolver;
use ContentBlocks\ContentBlocksBundle;
use ContentBlocks\Entity\ContentArea;
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
 * `content_blocks.structure` reaches the resolver every endpoint asks.
 */
final class StructureConfigTest extends TestCase
{
    public function testEverythingIsEditableByDefault(): void
    {
        $structure = $this->resolve([]);

        $this->assertSame(BuilderStructure::SECTIONS_EDITABLE, $structure->sections);
        $this->assertTrue($structure->canEditColumns());
    }

    public function testTheConfiguredModeReachesTheResolver(): void
    {
        $structure = $this->resolve(['structure' => ['sections' => 'hidden', 'columns' => false]]);

        $this->assertSame(BuilderStructure::SECTIONS_HIDDEN, $structure->sections);
        $this->assertFalse($structure->columns);
    }

    public function testAnUnknownModeIsRefused(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->build(['structure' => ['sections' => 'frozen']]);
    }

    public function testTheInterfaceDefaultsToTheConfiguredResolver(): void
    {
        $alias = $this->build([])->getAlias(BuilderStructureResolverInterface::class);

        $this->assertSame(ConfiguredBuilderStructureResolver::class, (string) $alias);
    }

    /**
     * @param array<string, mixed> $config
     */
    private function resolve(array $config): BuilderStructure
    {
        $container = $this->build($config);
        $sections = $this->bound($container, ConfiguredBuilderStructureResolver::class, 'string $structureSections');
        $columns = $this->bound($container, ConfiguredBuilderStructureResolver::class, 'bool $structureColumns');
        \assert(\is_string($sections) && \is_bool($columns));

        return (new ConfiguredBuilderStructureResolver($sections, $columns))->forArea(new ContentArea());
    }

    private function bound(ContainerBuilder $container, string $service, string $binding): mixed
    {
        $value = $container->getDefinition($service)->getBindings()[$binding]->getValues()[0];

        return $container->getParameterBag()->resolveValue($value);
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
     * @param array<string, mixed> $config
     *
     * @return array<string, mixed>
     */
    private function processConfig(array $config): array
    {
        $configDir = \dirname(__DIR__, 2) . '/config';
        $treeBuilder = new TreeBuilder('content_blocks');
        $loader = new DefinitionFileLoader($treeBuilder, new FileLocator($configDir));

        (new ContentBlocksBundle())->configure(
            new DefinitionConfigurator($treeBuilder, $loader, $configDir, 'services.php'),
        );

        return (new Processor())->process($treeBuilder->buildTree(), [$config]);
    }
}
