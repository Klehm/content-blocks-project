<?php

declare(strict_types=1);

namespace ContentBlocks\DependencyInjection;

use ContentBlocks\Form\Extension\BlockFormExtensionCollection;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Collects services tagged `content_blocks.block_form_extension` (via
 * {@see \ContentBlocks\Form\Extension\AsBlockFormExtension}) and wires them —
 * paired with the block type ids they target, priority-ordered — into the
 * {@see BlockFormExtensionCollection}.
 *
 * The target ids live in the tag rather than the interface so a host writes a
 * single `#[AsBlockFormExtension('button')]` and implements only buildForm().
 *
 * @internal Wiring detail of the bundle. See FREEZE-AUDIT.md.
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

        // Higher priority first; usort is stable on PHP 8, so extensions sharing
        // a priority keep their service-discovery order.
        usort($registrations, static fn (array $a, array $b): int => $b['priority'] <=> $a['priority']);

        $container->findDefinition(BlockFormExtensionCollection::class)
            ->setArgument(0, array_map(static fn (array $r): array => $r['pair'], $registrations));
    }
}
