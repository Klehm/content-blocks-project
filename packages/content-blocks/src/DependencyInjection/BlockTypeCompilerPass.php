<?php

declare(strict_types=1);

namespace ContentBlocks\DependencyInjection;

use ContentBlocks\BlockType\BlockTypeRegistry;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

final class BlockTypeCompilerPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->has(BlockTypeRegistry::class)) {
            return;
        }

        $definition = $container->findDefinition(BlockTypeRegistry::class);
        $taggedServices = $container->findTaggedServiceIds('content_blocks.block_type');

        foreach ($taggedServices as $id => $tags) {
            $definition->addMethodCall('register', [new Reference($id)]);
        }
    }
}
