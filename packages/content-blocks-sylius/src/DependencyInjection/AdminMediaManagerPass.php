<?php

declare(strict_types=1);

namespace ContentBlocks\Sylius\DependencyInjection;

use ContentBlocks\Media\Security\DenyAllMediaManager;
use ContentBlocks\Media\Security\MediaManagerInterface;
use ContentBlocks\Sylius\Media\AdminMediaManager;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Lets shop administrators manage the media library, in place of the media
 * package's deny-all default only: a host's own alias is left alone.
 *
 * @see docs/internals/sylius.md#the-media-library
 */
final class AdminMediaManagerPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasAlias(MediaManagerInterface::class) || !$container->has(AdminMediaManager::class)) {
            return;
        }
        if ((string) $container->getAlias(MediaManagerInterface::class) === DenyAllMediaManager::class) {
            $container->setAlias(MediaManagerInterface::class, AdminMediaManager::class);
        }
    }
}
