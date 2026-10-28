<?php

declare(strict_types=1);

namespace ContentBlocks\Sylius\DependencyInjection;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Points a package's seam at the plugin's service, only while it still points
 * at the package's default: a host's own alias is left alone.
 *
 * @see docs/internals/sylius.md#the-media-library
 */
final class DefaultAliasPass implements CompilerPassInterface
{
    public function __construct(
        private readonly string $interface,
        private readonly string $default,
        private readonly string $replacement,
    ) {
    }

    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasAlias($this->interface) || !$container->has($this->replacement)) {
            return;
        }
        if ((string) $container->getAlias($this->interface) === $this->default) {
            $container->setAlias($this->interface, $this->replacement);
        }
    }
}
