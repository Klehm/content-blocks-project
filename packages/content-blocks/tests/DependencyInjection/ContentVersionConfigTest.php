<?php

declare(strict_types=1);

namespace ContentBlocks\Tests\DependencyInjection;

use ContentBlocks\ContentBlocksBundle;
use ContentBlocks\Doctrine\ContentAreaTouchListener;
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
 * The host-owned content version is only useful if what a host writes in YAML is
 * what ends up stamped on their content. This walks that whole path: config →
 * parameter → the listener's constructor argument.
 */
final class ContentVersionConfigTest extends TestCase
{
    public function testTheConfiguredVersionReachesTheStampingListener(): void
    {
        $container = $this->build(['content_version' => 12]);

        $this->assertSame(12, $container->getParameter('content_blocks.content_version'));

        // The definition holds the parameter *reference*; resolving it is what
        // proves the two ends are actually connected.
        $argument = $container->getDefinition(ContentAreaTouchListener::class)->getArgument(0);
        $this->assertSame('%content_blocks.content_version%', $argument);
        $this->assertSame(
            12,
            $container->getParameterBag()->resolveValue($argument),
            'the listener stamps what the host configured, not a hardcoded default',
        );
    }

    public function testItDefaultsToOne(): void
    {
        // A host that never thinks about versioning still gets a coherent
        // stamp — "generation 1" — rather than null everywhere.
        $this->assertSame(1, $this->build([])->getParameter('content_blocks.content_version'));
    }

    public function testZeroAndNegativeVersionsAreRefused(): void
    {
        // Versions order (`WHERE content_version < N`), and null already means
        // "predates versioning". Allowing 0 would blur the two.
        $this->expectException(InvalidConfigurationException::class);
        $this->build(['content_version' => 0]);
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
