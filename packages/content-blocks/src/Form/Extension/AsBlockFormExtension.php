<?php

declare(strict_types=1);

namespace ContentBlocks\Form\Extension;

/**
 * Auto-registers a {@see BlockFormExtensionInterface} and declares which block
 * types it targets, via the {@see \ContentBlocks\DependencyInjection\BlockFormExtensionPass}.
 *
 * Keyed by block type **id** (string), not class — so it survives block
 * subclassing and matches the config keys (`content_blocks_kit.blocks.<type>`).
 *
 *     #[AsBlockFormExtension('button')]           // one block
 *     #[AsBlockFormExtension('button', 'card')]   // several blocks
 *     #[AsBlockFormExtension]                      // every block (global)
 *     #[AsBlockFormExtension('button', priority: 10)]
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class AsBlockFormExtension
{
    /** @var list<string> Targeted block type ids, or `['*']` for every block. */
    public readonly array $blockTypes;

    /**
     * @param string|list<string> $blockTypes One or more block type ids to target;
     *                                         omit (or pass none) to target every block.
     * @param int                 $priority   Higher runs first (fields appear earlier).
     *                                         Extensions sharing a priority keep discovery order.
     */
    public function __construct(
        string|array $blockTypes = [],
        public readonly int $priority = 0,
    ) {
        $normalized = \is_string($blockTypes) ? [$blockTypes] : array_values($blockTypes);

        // No explicit target = global. `'*'` is the wildcard the collection matches on.
        $this->blockTypes = [] === $normalized ? ['*'] : $normalized;
    }
}
