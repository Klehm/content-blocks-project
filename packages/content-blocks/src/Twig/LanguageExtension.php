<?php

declare(strict_types=1);

namespace ContentBlocks\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * `cb_lang(item, 'label')` prints ` lang="fr"` when a field is shown in
 * another language than the page's, as `_lang` records it; nothing otherwise.
 *
 * @see docs/guide/custom-blocks.md#text-shown-in-another-language
 */
final class LanguageExtension extends AbstractExtension
{
    public const KEY = '_lang';

    /**
     * @return list<TwigFunction>
     */
    public function getFunctions(): array
    {
        return [
            new TwigFunction('cb_lang', [$this, 'lang'], ['is_safe' => ['html']]),
        ];
    }

    public function lang(mixed $container, string $key): string
    {
        $tag = \is_array($container) ? ($container[self::KEY][$key] ?? null) : null;

        if (!\is_string($tag) || preg_match('/^[A-Za-z]{2,8}(?:-[A-Za-z0-9]{1,8})*$/', $tag) !== 1) {
            return '';
        }

        return ' lang="' . $tag . '"';
    }
}
