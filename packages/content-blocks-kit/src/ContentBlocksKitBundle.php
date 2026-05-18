<?php

declare(strict_types=1);

namespace ContentBlocks\Kit;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

final class ContentBlocksKitBundle extends AbstractBundle
{
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $container->import('../config/services.php');
    }

    public function prependExtension(ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        // Register assets path so AssetMapper + StimulusBundle can discover controllers.
        // The @ContentBlocksKit Twig namespace is auto-detected by AbstractBundle from <BundleRoot>/templates/,
        // which also gives `templates/bundles/ContentBlocksKitBundle/` priority for host overrides.
        $builder->prependExtensionConfig('framework', [
            'asset_mapper' => [
                'paths' => [
                    $this->getPath() . '/assets' => '@klehm/content-blocks-kit',
                ],
            ],
        ]);
    }

    public function getPath(): string
    {
        return \dirname(__DIR__);
    }
}
