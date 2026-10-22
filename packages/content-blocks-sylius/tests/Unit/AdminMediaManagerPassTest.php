<?php

declare(strict_types=1);

namespace ContentBlocks\Sylius\Tests\Unit;

use ContentBlocks\Media\Security\AllowAllMediaManager;
use ContentBlocks\Media\Security\DenyAllMediaManager;
use ContentBlocks\Media\Security\MediaManagerInterface;
use ContentBlocks\Sylius\DependencyInjection\AdminMediaManagerPass;
use ContentBlocks\Sylius\Media\AdminMediaManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;

final class AdminMediaManagerPassTest extends TestCase
{
    public function testItReplacesTheDenyAllDefault(): void
    {
        $container = $this->container(DenyAllMediaManager::class);

        (new AdminMediaManagerPass())->process($container);

        self::assertSame(AdminMediaManager::class, (string) $container->getAlias(MediaManagerInterface::class));
    }

    public function testItKeepsTheHostsOwnManager(): void
    {
        $container = $this->container(AllowAllMediaManager::class);

        (new AdminMediaManagerPass())->process($container);

        self::assertSame(AllowAllMediaManager::class, (string) $container->getAlias(MediaManagerInterface::class));
    }

    public function testItDoesNothingWithoutTheMediaPackage(): void
    {
        $container = new ContainerBuilder();

        (new AdminMediaManagerPass())->process($container);

        self::assertFalse($container->hasAlias(MediaManagerInterface::class));
    }

    private function container(string $manager): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->register($manager);
        $container->register(AdminMediaManager::class);
        $container->setAlias(MediaManagerInterface::class, $manager);

        return $container;
    }
}
