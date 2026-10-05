<?php

declare(strict_types=1);

namespace ContentBlocks\Tests\DependencyInjection;

use ContentBlocks\ContentBlocksBundle;
use ContentBlocks\Controller\SectionSidebarController;
use ContentBlocks\Twig\Component\BlockComponent;
use PHPUnit\Framework\Attributes\DataProvider;
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
 * `content_blocks.styling` reaches the two sidebars that offer the fields.
 */
final class StylingConfigTest extends TestCase
{
    /**
     * @param array<string, mixed> $config
     */
    #[DataProvider('configs')]
    public function testTheConfigResolvesPerSidebar(array $config, bool $block, bool $section): void
    {
        $container = $this->build($config);

        $this->assertSame($block, $container->getParameter('content_blocks.styling.block'));
        $this->assertSame($section, $container->getParameter('content_blocks.styling.section'));
    }

    /**
     * @return iterable<string, array{array<string, mixed>, bool, bool}>
     */
    public static function configs(): iterable
    {
        yield 'on by default' => [[], true, true];
        yield 'false turns both off' => [['styling' => false], false, false];
        yield 'true keeps both on' => [['styling' => true], true, true];
        yield 'blocks only' => [['styling' => ['block' => false]], false, true];
        yield 'sections only' => [['styling' => ['section' => false]], true, false];
    }

    public function testBothSidebarsReceiveTheirFlag(): void
    {
        $container = $this->build(['styling' => ['block' => false]]);

        $this->assertFalse($this->bound($container, BlockComponent::class, 'bool $blockStyling'));
        $this->assertTrue($this->bound($container, SectionSidebarController::class, 'bool $sectionStyling'));
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
