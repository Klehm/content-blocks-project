<?php

declare(strict_types=1);

namespace ContentBlocks\Kit\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Turns a stored choice value into a CSS class suffix, for the kit's views.
 *
 * The views used to inline a whitelist per field — `variant in ['primary',
 * 'secondary', 'outline', 'link'] ? variant : 'primary'` — which made a value a
 * host added through `content_blocks_kit.blocks.<type>.choices` unrenderable:
 * the picker offered it, the editor saved it, and the template quietly swapped
 * it back for the coded default. The list also had to be kept in step with
 * `choiceFields()` by hand, in ten places.
 *
 * What those whitelists were really protecting is narrower than a value list:
 * the value is interpolated into `class="cb-kit-btn--{{ variant }}"`, and
 * `Block.data` is not necessarily well-formed — it may predate the field, come
 * from an import, or have been hand-edited. Twig escapes the quotes, so this is
 * not an injection; but a value carrying a space would silently become a second
 * class, and one carrying nothing would leave a dangling `cb-kit-btn--`.
 *
 * So the rule is a *shape* check, not a membership one: keep the value when it
 * reads as a single class token, fall back otherwise. A configured value passes
 * (the host writes the matching CSS); a malformed one never reaches the markup.
 */
final class ChoiceTokenExtension extends AbstractExtension
{
    /**
     * Deliberately permissive within one token: letters, digits, `_` and `-`.
     * That is every value the kit codes, and every value a host is likely to
     * add — while excluding whitespace, quotes and angle brackets.
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
