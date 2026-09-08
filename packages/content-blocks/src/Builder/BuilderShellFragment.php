<?php

declare(strict_types=1);

namespace ContentBlocks\Builder;

/**
 * One piece of markup a bundle drops into the builder shell.
 *
 * A fragment is a Twig template rendered inside the `.cb-shell` element, after
 * the builder's own chrome. It is the UI half of what {@see BuilderAction} is
 * the trigger half of: an action gives a bundle a menu entry and an event, a
 * fragment gives it somewhere to put the dialog, the panel and the script that
 * react to that event — without a Stimulus controller, without an entry in any
 * host's `controllers.json`, and without an asset build.
 *
 * The template is rendered with {@see $context} and nothing else (no leaking
 * of the shell's own variables), plus the `area` being edited, which the core
 * always provides and which a fragment therefore may not declare itself.
 */
final class BuilderShellFragment
{
    /**
     * @param string               $template Twig template name, e.g. `@MyBundle/builder/history.html.twig`
     * @param array<string, mixed> $context  Variables the template is rendered with. `area` is reserved
     * @param int                  $priority Higher renders first; ties keep registration order
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
            throw new \InvalidArgumentException(sprintf(
                'Builder shell fragment "%s" declares an "area" context variable; that name is reserved for the ContentArea being edited.',
                $template,
            ));
        }
    }
}
