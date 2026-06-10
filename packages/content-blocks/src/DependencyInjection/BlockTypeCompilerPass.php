<?php

declare(strict_types=1);

namespace ContentBlocks\DependencyInjection;

use ContentBlocks\BlockType\BlockTypeRegistry;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\Compiler\PriorityTaggedServiceTrait;
use Symfony\Component\DependencyInjection\ContainerBuilder;

final class BlockTypeCompilerPass implements CompilerPassInterface
{
    use PriorityTaggedServiceTrait;

    public function process(ContainerBuilder $container): void
    {
        if (!$container->has(BlockTypeRegistry::class)) {
            return;
        }

        $definition = $container->findDefinition(BlockTypeRegistry::class);

        // findAndSortTaggedServices honours the `priority` tag attribute set by
        // #[AsContentBlock] — higher priority first. The registry's insertion
        // order is what the block-picker grid renders, so this controls it.
        $refs = $this->findAndSortTaggedServices('content_blocks.block_type', $container);

        foreach ($refs as $ref) {
            $definition->addMethodCall('register', [$ref]);
        }
    }
}
