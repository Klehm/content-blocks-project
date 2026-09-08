<?php

declare(strict_types=1);

namespace ContentBlocks\Builder;

/**
 * One Twig template a bundle drops inside `.cb-shell` — the UI half of what
 * {@see BuilderAction} is the trigger half of.
 *
 * @see docs/internals/builder-extensions.md#what-the-package-renders
 */
final class BuilderShellFragment
{
    /**
     * @param string               $template Twig template name
     * @param array<string, mixed> $context  `area` is reserved to the core
     * @param int                  $priority higher renders first
     */
    public function __construct(
        public readonly string $template,
        public readonly array $context = [],
        public readonly int $priority = 0,
    ) {
        if ($template === '') {
            throw new \InvalidArgumentException('A builder shell fragment needs a non-empty template name.');
        }
        // Silently overwriting it at render would be worse than refusing it
        // here: the fragment author would see their own value ignored.
        if (\array_key_exists('area', $context)) {
            throw new \InvalidArgumentException(sprintf('Builder shell fragment "%s" declares an "area" context variable; that name is reserved for the ContentArea being edited.', $template, ));
        }
    }
}
