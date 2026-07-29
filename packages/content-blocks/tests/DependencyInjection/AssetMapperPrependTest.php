<?php

declare(strict_types=1);

namespace ContentBlocks\Tests\DependencyInjection;

use ContentBlocks\ContentBlocksBundle;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\FrameworkBundle\DependencyInjection\FrameworkExtension;
use Symfony\Component\AssetMapper\AssetMapper;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;

/**
 * The bundle must boot on a host that builds its assets with Webpack Encore.
 *
 * `framework.asset_mapper` is declared with `canBeEnabled()`, whose
 * beforeNormalization turns *any* non-empty array into `enabled: true`. So
 * prepending `paths` does not merely describe a path — it enables the
 * component, and FrameworkExtension then throws:
 *
 *     AssetMapper support cannot be enabled as the AssetMapper component is not
 *     installed. Try running "composer require symfony/asset-mapper".
 *
 * Neither package requires symfony/asset-mapper, so an unguarded prepend is a
 * boot failure for every Encore host — most of the Sylius 1.x install base.
 *
 * This suite runs in exactly that shape: the package's own vendor/ has
 * stimulus-bundle and no asset-mapper, so `testTheBundleBootsWithoutAssetMapper`
 * is a real reproduction rather than a simulation. It stays honest if
 * asset-mapper is ever added to require-dev — the assertions follow
 * class_exists() rather than assuming an answer.
 */
final class AssetMapperPrependTest extends TestCase
{
    public function testTheBundleBootsWithoutAssetMapper(): void
    {
        $container = $this->prepend();

        // Loading FrameworkExtension is where the LogicException fired.
        (new FrameworkExtension())->load(
            array_merge(
                [['secret' => 'test', 'http_method_override' => false]],
                $container->getExtensionConfig('framework'),
            ),
            $container,
        );

        $this->addToAssertionCount(1);
    }

    public function testThePathIsRegisteredOnlyWhenAssetMapperIsInstalled(): void
    {
        $prepended = array_merge(...array_values($this->prepend()->getExtensionConfig('framework')) ?: [[]]);

        if (class_exists(AssetMapper::class)) {
            $this->assertArrayHasKey(
                'asset_mapper',
                $prepended,
                'an AssetMapper host still gets the controllers discovered automatically',
            );
            $this->assertSame(
                ['@klehm/content-blocks'],
                array_values($prepended['asset_mapper']['paths']),
                'registered under the namespace assets/package.json declares',
            );

            return;
        }

        $this->assertArrayNotHasKey(
            'asset_mapper',
            $prepended,
            'an Encore host reads the same controllers through @symfony/stimulus-bridge instead',
        );
    }

    public function testTheFormThemeIsPrependedRegardless(): void
    {
        // The guard must be narrow: only the asset path is build-tool specific.
        // Twig wiring is not, and silently dropping it would break the widget
        // on precisely the hosts this fix is for.
        $twig = array_merge(...array_values($this->prepend()->getExtensionConfig('twig')) ?: [[]]);

        $this->assertContains('@ContentBlocks/form/content_area_widget.html.twig', $twig['form_themes']);
    }

    private function prepend(): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.debug', false);
        $container->setParameter('kernel.environment', 'test');
        $container->setParameter('kernel.project_dir', \dirname(__DIR__, 2));
        $container->setParameter('kernel.build_dir', sys_get_temp_dir());
        $container->setParameter('kernel.cache_dir', sys_get_temp_dir());
        $container->setParameter('kernel.bundles', []);
        $container->setParameter('kernel.bundles_metadata', []);
        $container->setParameter('kernel.runtime_environment', 'test');
        $container->setParameter('kernel.charset', 'UTF-8');
        $container->setParameter('kernel.container_class', 'TestContainer');

        $configDir = \dirname(__DIR__, 2) . '/config';
        $instanceof = [];

        (new ContentBlocksBundle())->prependExtension(
            new ContainerConfigurator(
                $container,
                new PhpFileLoader($container, new FileLocator($configDir)),
                $instanceof,
                $configDir,
                'services.php',
            ),
            $container,
        );

        return $container;
    }
}
