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
 * `content_blocks.section.initial_settings`: validated like a preset's
 * settings, and handed to the controller exactly as written.
 */
final class SectionInitialSettingsConfigTest extends TestCase
{
    public function testItIsEmptyWhenNotConfigured(): void
    {
        $this->assertSame([], $this->build([])->getParameter('content_blocks.section.initial_settings'));
    }

    public function testOnlyTheKeysTheHostWroteComeThrough(): void
    {
        $settings = [
            'widthMode' => 'centered',
            'stylingCustom' => true,
            'styling' => [
                'padding' => [
                    'desktop' => ['top' => 12, 'right' => 12, 'bottom' => 12, 'left' => 12, 'linked' => true],
                ],
            ],
        ];

        $container = $this->build(['section' => ['initial_settings' => $settings]]);

        $this->assertSame($settings, $container->getParameter('content_blocks.section.initial_settings'));
        // The width knobs beside it keep their own defaults.
        $this->assertSame('full', $container->getParameter('content_blocks.section.default_width_mode'));
    }

    /** Same typed tree as a preset, so a typo fails at cache:clear. */
    public function testAnUnknownKeyIsRefused(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        $this->build(['section' => ['initial_settings' => ['widthMod' => 'centered']]]);
    }

    public function testAnInvalidValueIsRefused(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        $this->build(['section' => ['initial_settings' => ['widthMode' => 'boxed']]]);
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
