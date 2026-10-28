<?php

declare(strict_types=1);

namespace ContentBlocks\Sylius\Tests\Unit;

use ContentBlocks\Media\Security\AllowAllMediaManager;
use ContentBlocks\Media\Security\DenyAllMediaManager;
use ContentBlocks\Media\Security\MediaManagerInterface;
use ContentBlocks\Sylius\DependencyInjection\DefaultAliasPass;
use ContentBlocks\Sylius\Media\AdminMediaManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;

final class DefaultAliasPassTest extends TestCase
{
    public function testItReplacesThePackagesDefault(): void
    {
        $container = $this->container(DenyAllMediaManager::class);

        $this->pass()->process($container);

        self::assertSame(AdminMediaManager::class, (string) $container->getAlias(MediaManagerInterface::class));
    }

    public function testItKeepsTheHostsOwnService(): void
    {
        $container = $this->container(AllowAllMediaManager::class);

        $this->pass()->process($container);

        self::assertSame(AllowAllMediaManager::class, (string) $container->getAlias(MediaManagerInterface::class));
    }

    public function testItDoesNothingWithoutThePackage(): void
    {
        $container = new ContainerBuilder();

        $this->pass()->process($container);

        self::assertFalse($container->hasAlias(MediaManagerInterface::class));
    }

    public function testItDoesNothingWhenThePluginServiceIsOff(): void
    {
        $container = new ContainerBuilder();
        $container->register(DenyAllMediaManager::class);
        $container->setAlias(MediaManagerInterface::class, DenyAllMediaManager::class);

        $this->pass()->process($container);

        self::assertSame(DenyAllMediaManager::class, (string) $container->getAlias(MediaManagerInterface::class));
    }

    private function pass(): DefaultAliasPass
    {
        return new DefaultAliasPass(MediaManagerInterface::class, DenyAllMediaManager::class, AdminMediaManager::class);
    }

    private function container(string $service): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->register($service);
        $container->register(AdminMediaManager::class);
        $container->setAlias(MediaManagerInterface::class, $service);

        return $container;
    }
}
