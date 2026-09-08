<?php

declare(strict_types=1);

namespace ContentBlocks\Builder;

use Symfony\Contracts\Translation\TranslatableInterface;

/**
 * One entry in the builder topbar's Actions menu. Clicking it dispatches a
 * `cb:builder:action` event carrying {@see $key}; the rest is the host's.
 *
 * @see docs/internals/builder-extensions.md#what-the-package-renders
 */
final class BuilderAction
{
    /**
     * @param string                            $key      carried by the event
     * @param string|TranslatableInterface      $label    translated at render
     * @param string|null                       $icon     inline SVG or a glyph,
     *                                                    rendered raw — trusted
     *                                                    code only
     * @param string|TranslatableInterface|null $title    falls back to $label
     * @param int                               $priority higher sorts first
     */
    public function __construct(
        public readonly string $key,
        public readonly string|TranslatableInterface $label,
        public readonly ?string $icon = null,
        public readonly string|TranslatableInterface|null $title = null,
        public readonly int $priority = 0,
    ) {
    }

    /**
     * Normalises the `topbar_actions` array shape, so a per-form action and a
     * bundle-provided one are the same thing by the time a template sees them.
     *
     * @param array<string, mixed> $definition
     */
    public static function fromArray(array $definition): self
    {
        $key = $definition['key'] ?? null;
        if (!is_string($key) || $key === '') {
            throw new \InvalidArgumentException('A builder action needs a non-empty string "key".');
        }

        $label = $definition['label'] ?? null;
        if (!is_string($label) && !$label instanceof TranslatableInterface) {
            throw new \InvalidArgumentException(sprintf('Builder action "%s" needs a "label" (string or TranslatableInterface).', $key, ));
        }

        $icon = $definition['icon'] ?? null;
        $title = $definition['title'] ?? null;

        return new self(
            $key,
            $label,
            is_string($icon) && $icon !== '' ? $icon : null,
            is_string($title) || $title instanceof TranslatableInterface ? $title : null,
            is_int($definition['priority'] ?? null) ? $definition['priority'] : 0,
        );
    }
}
