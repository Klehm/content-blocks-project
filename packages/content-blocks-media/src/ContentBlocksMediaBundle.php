<?php

declare(strict_types=1);

namespace ContentBlocks\Media;

use ContentBlocks\Media\Library\MediaKind;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

/**
 * The media library: a row per stored file, and the dialog that picks one
 * from the builder. The stored value stays a path.
 *
 * @see docs/internals/media.md
 */
final class ContentBlocksMediaBundle extends AbstractBundle
{
    protected string $extensionAlias = 'content_blocks_media';

    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->rootNode()
            ->children()
                ->arrayNode('kinds')
                    ->info('The kinds of file the library offers on the upload widget: image, video or both.')
                    ->defaultValue([MediaKind::IMAGE, MediaKind::VIDEO])
                    ->scalarPrototype()
                        ->validate()
                            ->ifNotInArray([MediaKind::IMAGE, MediaKind::VIDEO])
                            ->thenInvalid('Unknown kind %s: expected "image" or "video".')
                        ->end()
                    ->end()
                ->end()
            ->end();
    }

    /**
     * @param array<string, mixed> $config
     */
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $container->import('../config/services.php');

        $container->parameters()
            ->set('content_blocks_media.kinds', array_values(array_unique($config['kinds'])));
    }

    public function prependExtension(ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        // Guarded so the bundle still boots without Doctrine, as in a test.
        if (!isset($builder->getExtensions()['doctrine'])) {
            return;
        }

        $builder->prependExtensionConfig('doctrine', [
            'orm' => [
                'mappings' => [
                    'ContentBlocksMedia' => [
                        'type' => 'attribute',
                        'is_bundle' => false,
                        'dir' => $this->getPath() . '/src/Entity',
                        'prefix' => 'ContentBlocks\\Media\\Entity',
                        'alias' => 'ContentBlocksMedia',
                    ],
                ],
            ],
        ]);
    }

    public function getPath(): string
    {
        return \dirname(__DIR__);
    }
}
