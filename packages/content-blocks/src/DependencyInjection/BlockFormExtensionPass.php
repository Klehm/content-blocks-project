<?php

declare(strict_types=1);

namespace ContentBlocks\DependencyInjection;

use ContentBlocks\Form\Extension\BlockFormExtensionCollection;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Pairs each block form extension with the type ids it targets, in priority
 * order, and feeds {@see BlockFormExtensionCollection}.
 *
 * @see docs/internals/bundle-boot.md#autoconfiguration
 *
 * @internal wiring detail of the bundle
 */
final class BlockFormExtensionPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->has(BlockFormExtensionCollection::class)) {
            return;
        }

        $registrations = [];
        foreach ($container->findTaggedServiceIds('content_blocks.block_form_extension') as $id => $tags) {
            foreach ($tags as $attributes) {
                $blockTypes = $attributes['block_types'] ?? ['*'];
                if (!\is_array($blockTypes) || [] === $blockTypes) {
                    $blockTypes = ['*'];
                }

                $registrations[] = [
                    'priority' => (int) ($attributes['priority'] ?? 0),
                    'pair' => [new Reference($id), array_values($blockTypes)],
                ];
            }
        }

        // usort is stable on PHP 8, so a shared priority keeps discovery order.
        usort($registrations, static fn (array $a, array $b): int => $b['priority'] <=> $a['priority']);

        $container->findDefinition(BlockFormExtensionCollection::class)
            ->setArgument(0, array_map(static fn (array $r): array => $r['pair'], $registrations));
    }
}
