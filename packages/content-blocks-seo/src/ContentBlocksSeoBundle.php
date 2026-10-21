<?php

declare(strict_types=1);

namespace ContentBlocks\Seo;

use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

/**
 * On-page SEO audit in the builder. Installing it adds a panel and changes
 * nothing a visitor sees.
 *
 * @see docs/internals/seo.md
 */
final class ContentBlocksSeoBundle extends AbstractBundle
{
    protected string $extensionAlias = 'content_blocks_seo';

    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->rootNode()
            ->children()
                ->integerNode('paragraph_max_words')
                    ->info('A paragraph longer than this is reported. 0 turns the check off.')
                    ->defaultValue(150)
                    ->min(0)
                ->end()
                ->arrayNode('generic_link_texts')
                    ->info('Link texts that say nothing of where they lead. Compared ignoring case, accents and punctuation. Replaces the default list (English, French, German, Spanish).')
                    ->defaultValue(SeoChecks::GENERIC_LINK_TEXTS)
                    ->scalarPrototype()->cannotBeEmpty()->end()
                ->end()
                ->arrayNode('disabled_checks')
                    ->info('Checks left out of the audit, by name: ' . implode(', ', SeoChecks::ALL) . '.')
                    ->scalarPrototype()
                        ->validate()
                            ->ifNotInArray(SeoChecks::ALL)
                            ->thenInvalid('Unknown check %s. Known checks: ' . implode(', ', SeoChecks::ALL) . '.')
                        ->end()
                    ->end()
                ->end()
            ->end();
    }

    /**
     * @param array{
     *     paragraph_max_words: int,
     *     generic_link_texts: list<string>,
     *     disabled_checks: list<string>,
     * } $config
     */
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $container->import('../config/services.php');

        $container->parameters()->set('content_blocks_seo.audit_options', [
            'paragraphMaxWords' => $config['paragraph_max_words'],
            'genericLinkTexts' => array_values(array_unique($config['generic_link_texts'])),
            'disabled' => array_values(array_unique($config['disabled_checks'])),
        ]);
    }

    public function getPath(): string
    {
        return \dirname(__DIR__);
    }
}
