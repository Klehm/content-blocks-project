<?php

declare(strict_types=1);

namespace ContentBlocks\Kit\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Turns a stored choice value into a CSS class suffix. A **shape** check, not a
 * membership one, so a host-configured value renders.
 *
 * @see docs/internals/kit.md#why-views-check-a-token-shape-not-a-value-list
 */
final class ChoiceTokenExtension extends AbstractExtension
{
    /**
     * Permissive within one token, excluding whitespace, quotes and angle
     * brackets.
     */
    private const TOKEN = '#^[A-Za-z0-9_-]{1,64}$#';

    public function getFunctions(): array
    {
        return [
            new TwigFunction('cb_kit_token', $this->token(...)),
        ];
    }

    /**
     * @param mixed  $value    the stored choice value, of unknown provenance
     * @param string $fallback the block's coded default for that field
     */
    public function token(mixed $value, string $fallback): string
    {
        if (!\is_string($value) && !\is_int($value)) {
            return $fallback;
        }

        $value = (string) $value;

        return preg_match(self::TOKEN, $value) === 1 ? $value : $fallback;
    }
}
