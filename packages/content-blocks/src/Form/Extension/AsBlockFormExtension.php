<?php

declare(strict_types=1);

namespace ContentBlocks\Form\Extension;

/**
 * Auto-registers a {@see BlockFormExtensionInterface} and declares the block
 * type **ids** it targets — ids, not classes, so subclassing does not break it.
 *
 * @see docs/internals/forms.md#why-form-extensions-are-a-package-seam
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class AsBlockFormExtension
{
    /** @var list<string> targeted type ids, or `['*']` for every block */
    public readonly array $blockTypes;

    /**
     * @param string|list<string> $blockTypes type ids; omit to target all
     * @param int                 $priority   higher runs first, ties keep
     *                                        discovery order
     */
    public function __construct(
        string|array $blockTypes = [],
        public readonly int $priority = 0,
    ) {
        $normalized = \is_string($blockTypes) ? [$blockTypes] : array_values($blockTypes);

        // No explicit target = global; `'*'` is the collection's wildcard.
        $this->blockTypes = [] === $normalized ? ['*'] : $normalized;
    }
}
