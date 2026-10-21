<?php

declare(strict_types=1);

namespace ContentBlocks\Sylius\DependencyInjection;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * The builder's Live component posts to the Sylius admin's Live route: a
 * Sylius app mounts no `ux_live_component`, and the admin firewall covers it.
 *
 * @see docs/internals/sylius.md#who-may-edit
 */
final class AdminLiveComponentRoutePass implements CompilerPassInterface
{
    public const ROUTE = 'sylius_admin_live_component';
    private const COMPONENT = 'ContentBlocks:Block';

    public function process(ContainerBuilder $container): void
    {
        foreach ($container->findTaggedServiceIds('twig.component') as $id => $tags) {
            $definition = $container->getDefinition($id);
            $changed = false;
            foreach ($tags as $i => $tag) {
                if (($tag['key'] ?? null) === self::COMPONENT && ($tag['route'] ?? 'ux_live_component') === 'ux_live_component') {
                    $tags[$i]['route'] = self::ROUTE;
                    $changed = true;
                }
            }
            if ($changed) {
                $definition->clearTag('twig.component');
                foreach ($tags as $tag) {
                    $definition->addTag('twig.component', $tag);
                }
            }
        }
    }
}
