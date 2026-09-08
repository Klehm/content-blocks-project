<?php

declare(strict_types=1);

namespace ContentBlocks\I18n;

use ContentBlocks\I18n\Machine\TranslationProviderInterface;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

/**
 * Content translation — one shared layout, per-locale field values. Installing
 * it changes nothing until a translation row exists.
 *
 * @see docs/internals/i18n.md#one-layout-per-locale-values
 */
final class ContentBlocksI18nBundle extends AbstractBundle
{
    protected string $extensionAlias = 'content_blocks_i18n';

    /**
     * Semantic config: `source_locale`, `locales`, and a default machine
     * provider. No engine adapter ships here.
     *
     * @see docs/internals/i18n.md#config-and-mounting
     */
    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->rootNode()
            ->children()
                ->scalarNode('source_locale')
                    ->info('The locale a block\'s own `data` is written in. It is never a translation target and has no rows of its own.')
                    ->defaultValue('en')
                    ->cannotBeEmpty()
                ->end()
                ->arrayNode('locales')
                    ->info('Locales editors can translate into. Accepts bare codes or { code, label } maps; the source locale is ignored if listed.')
                    ->beforeNormalization()
                        // A flat list of codes or a map with labels: both
                        // spellings are natural, so accept either.
                        ->always(static function ($locales): array {
                            if (!\is_array($locales)) {
                                return [];
                            }

                            return array_map(
                                static fn ($locale) => \is_array($locale) ? $locale : ['code' => (string) $locale],
                                $locales,
                            );
                        })
                    ->end()
                    ->arrayPrototype()
                        ->children()
                            ->scalarNode('code')->isRequired()->cannotBeEmpty()->end()
                            ->scalarNode('label')->defaultNull()->end()
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('machine')
                    ->info('Machine translation. Any provider is optional: with none configured the UI reports "not configured" rather than failing.')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('default')
                            ->info('Name of the provider used when a request names none. Leave null to use the only registered one.')
                            ->defaultNull()
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

        $labels = [];
        $codes = [];

        foreach ($config['locales'] as $locale) {
            $codes[] = $locale['code'];

            if (($locale['label'] ?? null) !== null) {
                $labels[$locale['code']] = $locale['label'];
            }
        }

        $container->parameters()
            ->set('content_blocks_i18n.source_locale', $config['source_locale'])
            ->set('content_blocks_i18n.locales', $codes)
            ->set('content_blocks_i18n.locale_labels', $labels)
            ->set('content_blocks_i18n.machine.default', $config['machine']['default']);
    }

    public function prependExtension(ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        // The workbench is a page this bundle renders in full, so it needs its
        // own namespace — not one the host declares but never authors in.
        if (isset($builder->getExtensions()['twig'])) {
            $builder->prependExtensionConfig('twig', [
                'paths' => [$this->getPath() . '/templates' => 'ContentBlocksI18n'],
            ]);
        }

        // So a host hand-writes no mapping for a table it never touches.
        // Guarded so the bundle still boots without Doctrine, as in a test.
        if (!isset($builder->getExtensions()['doctrine'])) {
            return;
        }

        $builder->prependExtensionConfig('doctrine', [
            'orm' => [
                'mappings' => [
                    'ContentBlocksI18n' => [
                        'type' => 'attribute',
                        'is_bundle' => false,
                        'dir' => $this->getPath() . '/src/Entity',
                        'prefix' => 'ContentBlocks\\I18n\\Entity',
                        'alias' => 'ContentBlocksI18n',
                    ],
                ],
            ],
        ]);
    }

    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        $container->registerForAutoconfiguration(TranslationProviderInterface::class)
            ->addTag('content_blocks_i18n.translation_provider');
    }

    public function getPath(): string
    {
        return \dirname(__DIR__);
    }
}
