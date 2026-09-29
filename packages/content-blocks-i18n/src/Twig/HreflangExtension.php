<?php

declare(strict_types=1);

namespace ContentBlocks\I18n\Twig;

use ContentBlocks\Entity\ContentArea;
use ContentBlocks\I18n\Locale\AlternatePageLinks;
use ContentBlocks\I18n\Locale\TranslationLocales;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * `<link rel="alternate" hreflang>` tags for a public page's `<head>`, and the
 * data behind them for a host writing its own markup.
 *
 * @see docs/guide/translation.md#hreflang-links
 */
final class HreflangExtension extends AbstractExtension
{
    public function __construct(
        private readonly AlternatePageLinks $links,
        private readonly TranslationLocales $locales,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('cb_i18n_alternates', $this->alternates(...)),
            new TwigFunction('cb_i18n_hreflang', $this->hreflang(...), ['is_safe' => ['html']]),
        ];
    }

    /**
     * @return list<array{locale: string, hreflang: string, url: string}>
     */
    public function alternates(?ContentArea $area): array
    {
        return $area === null ? [] : $this->links->forArea($area);
    }

    /**
     * One tag per language, plus `x-default` on the source's URL. Nothing for
     * a page in one language only: an alternate needs a second language.
     */
    public function hreflang(?ContentArea $area, bool $xDefault = true): string
    {
        $links = $this->alternates($area);

        if (\count($links) < 2) {
            return '';
        }

        $tags = array_map(static fn (array $link): string => self::tag($link['hreflang'], $link['url']), $links);

        // The source comes first when the resolver gives it a URL.
        if ($xDefault && $this->locales->isSource($links[0]['locale'])) {
            $tags[] = self::tag('x-default', $links[0]['url']);
        }

        return implode("\n", $tags);
    }

    private static function tag(string $hreflang, string $url): string
    {
        return \sprintf(
            '<link rel="alternate" hreflang="%s" href="%s">',
            htmlspecialchars($hreflang, \ENT_QUOTES | \ENT_HTML5, 'UTF-8'),
            htmlspecialchars($url, \ENT_QUOTES | \ENT_HTML5, 'UTF-8'),
        );
    }
}
