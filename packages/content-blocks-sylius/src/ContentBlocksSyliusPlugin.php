<?php

declare(strict_types=1);

namespace ContentBlocks\Sylius;

use ContentBlocks\Image\ImageUrlResolverInterface;
use ContentBlocks\Image\PassthroughImageUrlResolver;
use ContentBlocks\Media\Security\DenyAllMediaManager;
use ContentBlocks\Media\Security\MediaManagerInterface;
use ContentBlocks\Sylius\DependencyInjection\AdminLiveComponentRoutePass;
use ContentBlocks\Sylius\DependencyInjection\DefaultAliasPass;
use ContentBlocks\Sylius\Entity\ContentPage;
use ContentBlocks\Sylius\Entity\ContentPageTranslation;
use ContentBlocks\Sylius\Form\Type\ContentPageTranslationType;
use ContentBlocks\Sylius\Form\Type\ContentPageType;
use ContentBlocks\Sylius\Image\LiipImageUrlResolver;
use ContentBlocks\Sylius\Media\AdminMediaManager;
use ContentBlocks\Sylius\Slot\SlotType;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;
use Symfony\Component\Yaml\Yaml;

/**
 * ContentBlocks in a Sylius 2 shop: CMS pages, builder content on native
 * pages, catalog blocks and Bootstrap markup for the kit.
 *
 * @see docs/internals/sylius.md
 */
final class ContentBlocksSyliusPlugin extends AbstractBundle
{
    protected string $extensionAlias = 'content_blocks_sylius';

    public function build(ContainerBuilder $container): void
    {
        // After attribute autoconfiguration, before the Twig component pass.
        $container->addCompilerPass(new AdminLiveComponentRoutePass(), PassConfig::TYPE_BEFORE_OPTIMIZATION, 10);
        $container->addCompilerPass(new DefaultAliasPass(
            MediaManagerInterface::class,
            DenyAllMediaManager::class,
            AdminMediaManager::class,
        ));
        $container->addCompilerPass(new DefaultAliasPass(
            ImageUrlResolverInterface::class,
            PassthroughImageUrlResolver::class,
            LiipImageUrlResolver::class,
        ));
    }

    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->rootNode()
            ->children()
                ->enumNode('kit_markup')
                    ->info('bootstrap: the kit blocks render Bootstrap 5 markup, like the Sylius shop. kit: their own cb-kit-* markup.')
                    ->values(['bootstrap', 'kit'])
                    ->defaultValue('bootstrap')
                ->end()
                ->booleanNode('images')
                    ->info("Serve the builder's images through LiipImagine: WebP, sized, with a srcset.")
                    ->defaultTrue()
                ->end()
                ->arrayNode('slots')
                    ->info('Native shop pages whose content can be edited with the builder.')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode(SlotType::HOMEPAGE)->defaultTrue()->end()
                        ->booleanNode(SlotType::PRODUCT)->defaultTrue()->end()
                        ->booleanNode(SlotType::TAXON)->defaultTrue()->end()
                    ->end()
                ->end()
            ->end();
    }

    /**
     * @param array{
     *     kit_markup: 'bootstrap'|'kit',
     *     images: bool,
     *     slots: array<string, bool>,
     * } $config
     */
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $container->parameters()
            ->set('content_blocks_sylius.kit_markup', $config['kit_markup'])
            ->set('content_blocks_sylius.images', $config['images'])
            ->set('content_blocks_sylius.slots', array_keys(array_filter($config['slots'])));

        $container->import('../config/services.php');
    }

    public function prependExtension(ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $builder->prependExtensionConfig('doctrine', [
            'orm' => [
                'mappings' => [
                    'ContentBlocks' => [
                        'type' => 'attribute',
                        'is_bundle' => false,
                        'dir' => self::packagePath(\ContentBlocks\Entity\ContentArea::class),
                        'prefix' => 'ContentBlocks\\Entity',
                        'alias' => 'ContentBlocks',
                    ],
                    'ContentBlocksSylius' => [
                        'type' => 'attribute',
                        'is_bundle' => false,
                        'dir' => $this->getPath() . '/src/Entity',
                        'prefix' => 'ContentBlocks\\Sylius\\Entity',
                        'alias' => 'ContentBlocksSylius',
                    ],
                ],
            ],
        ]);

        $builder->prependExtensionConfig('sylius_resource', [
            'resources' => [
                'content_blocks_sylius.content_page' => [
                    'driver' => 'doctrine/orm',
                    'classes' => [
                        'model' => ContentPage::class,
                        'form' => ContentPageType::class,
                    ],
                    'translation' => [
                        'classes' => [
                            'model' => ContentPageTranslation::class,
                            'form' => ContentPageTranslationType::class,
                        ],
                    ],
                ],
            ],
        ]);

        $builder->prependExtensionConfig('content_blocks', [
            'picker' => ['categories' => ['commerce' => 'content_blocks_sylius.picker.commerce']],
        ]);

        if ($builder->hasExtension('content_blocks_i18n')) {
            $builder->prependExtensionConfig('content_blocks_i18n', ['source_locale' => '%locale%']);
        }

        // Read from the public directory, where the uploads are served.
        $filters = [];
        foreach (LiipImageUrlResolver::FILTERS as $width => $name) {
            $filters[$name] = [
                'data_loader' => 'content_blocks_sylius',
                'format' => 'webp',
                'quality' => 75,
                'filters' => ['thumbnail' => ['size' => [$width, $width * 10], 'mode' => 'inset', 'allow_upscale' => false]],
            ];
        }
        $builder->prependExtensionConfig('liip_imagine', [
            'loaders' => ['content_blocks_sylius' => ['filesystem' => ['data_root' => '%kernel.project_dir%/public']]],
            'filter_sets' => $filters,
        ]);

        $files = [
            ...glob($this->getPath() . '/config/app/sylius_grid/*.yaml') ?: [],
            ...glob($this->getPath() . '/config/app/sylius_twig_hooks/*.yaml') ?: [],
        ];
        if ($builder->hasExtension('content_blocks_i18n')) {
            $files[] = $this->getPath() . '/config/app/i18n/sylius_twig_hooks.yaml';
        }
        foreach ($files as $file) {
            foreach (Yaml::parseFile($file) as $extension => $config) {
                $builder->prependExtensionConfig($extension, $config);
            }
        }
    }

    public function getPath(): string
    {
        return \dirname(__DIR__);
    }

    /**
     * The directory holding the class's file.
     *
     * @param class-string $class
     */
    private static function packagePath(string $class): string
    {
        return \dirname((string) (new \ReflectionClass($class))->getFileName());
    }
}
