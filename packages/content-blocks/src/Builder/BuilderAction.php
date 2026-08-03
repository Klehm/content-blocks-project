<?php

declare(strict_types=1);

namespace ContentBlocks\Builder;

use Symfony\Contracts\Translation\TranslatableInterface;

/**
 * One entry in the builder topbar's Actions menu.
 *
 * The package renders the entry and nothing else: clicking it dispatches a
 * single `cb:builder:action` DOM event carrying this action's {@see $key}.
 * What the action *does* is the host's business — it listens once on the shell
 * and switches on the key. That keeps the package free of any opinion about
 * what an editor might want to do with an area.
 */
final class BuilderAction
{
    /**
     * @param string                           $key      Stable identifier carried by the `cb:builder:action` event
     * @param string|TranslatableInterface     $label    Menu text; a TranslatableInterface is translated at render
     * @param string|null                      $icon     Optional inline SVG or a single glyph. Rendered raw, so it
     *                                                   must come from trusted code — never interpolate user input
     * @param string|TranslatableInterface|null $title   Tooltip; falls back to the label
     * @param int                              $priority Higher sorts first; ties keep registration order
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
     * Builds an action from the associative-array shape accepted by the
     * `topbar_actions` form option, so a per-form action and a bundle-provided
     * one end up as the same thing by the time the template sees them.
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
            throw new \InvalidArgumentException(sprintf(
                'Builder action "%s" needs a "label" (string or TranslatableInterface).',
                $key,
            ));
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
