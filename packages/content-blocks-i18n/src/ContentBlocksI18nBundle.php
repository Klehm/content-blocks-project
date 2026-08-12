<?php

declare(strict_types=1);

namespace ContentBlocks\I18n;

use ContentBlocks\I18n\Machine\DeepLTranslationProvider;
use ContentBlocks\I18n\Machine\TranslationProviderInterface;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

/**
 * Content translation for ContentBlocks — one shared layout, per-locale field
 * values.
 *
 * The structure of an area (sections, columns, block order, styling) is
 * language-agnostic and stays shared; only fields a block type tagged
 * `cb_translatable` are swapped per locale. That is the compromise the roadmap
 * set, and it is what makes a translated page impossible to drift structurally
 * from its source.
 *
 * Installing this bundle changes nothing about existing output: with no
 * translation rows and no locale resolved, the render pipeline returns the
 * block's own data untouched.
 */
final class ContentBlocksI18nBundle extends AbstractBundle
{
    protected string $extensionAlias = 'content_blocks_i18n';

    /**
     * ```yaml
     * content_blocks_i18n:
     *     source_locale: en              # the language block data itself is written in
     *     locales:                       # everything editors can translate into
     *         - fr
     *         - { code: de, label: 'Deutsch' }
     *     machine:
     *         default: deepl             # provider used when the caller names none
     *         deepl:
     *             api_key: '%env(DEEPL_API_KEY)%'
     * ```
     */
    public function configure(DefinitionConfigurator $definition): void
    {
        // @phpstan-ignore-next-line method.notFound (ArrayNodeDefinition is the concrete root)
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
                        // Both spellings are natural — a flat list of codes for
                        // the common case, a map when a display label needs
                        // overriding — so accept either rather than making
                        // every host write the verbose form.
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
                        ->arrayNode('deepl')
                            ->addDefaultsIfNotSet()
                            ->children()
                                ->scalarNode('api_key')
                                    ->info('Declaring this key registers the shipped DeepL provider; omit the node entirely to keep it unregistered. Note an `%env()%` placeholder always counts as declared — the container cannot read env values at compile time — so a host that wants DeepL only sometimes should omit the node in the configs where it is unwanted rather than rely on an empty variable.')
                                    ->defaultNull()
                                ->end()
                                ->scalarNode('endpoint')
                                    ->info('Override the API endpoint. Derived from the key by default (a `:fx` suffix means the free tier, which uses a different host).')
                                    ->defaultNull()
                                ->end()
                                ->arrayNode('locale_map')
                                    ->info('Host locale => DeepL language code, for the cases the mechanical mapping gets wrong.')
                                    ->scalarPrototype()->end()
                                ->end()
                            ->end()
                        ->end()
                    ->end()
                ->end()
            ->end();
    }

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

        // Same opt-in shape the core uses for LocalFileStorage: a provider that
        // needs credentials is only registered once it has them, so it can
        // never appear in the picker as an option that always fails.
        $deepl = $config['machine']['deepl'];

        if ($deepl['api_key'] !== null && $deepl['api_key'] !== '') {
            $container->services()
                ->set(DeepLTranslationProvider::class)
                ->args([
                    new Reference('http_client'),
                    $deepl['api_key'],
                    $deepl['endpoint'],
                    $deepl['locale_map'],
                ])
                ->tag('content_blocks_i18n.translation_provider');
        }
    }

    public function prependExtension(ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        // Map the bundle's own entity so a host does not have to add a
        // `doctrine.orm.mappings` entry by hand for a table it never touches
        // directly. Guarded on the extension being present so the bundle can
        // still boot in a non-Doctrine context (a unit test kernel).
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
