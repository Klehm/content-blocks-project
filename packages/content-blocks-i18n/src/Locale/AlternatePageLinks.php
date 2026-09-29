<?php

declare(strict_types=1);

namespace ContentBlocks\I18n\Locale;

use ContentBlocks\Entity\ContentArea;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Every language a page exists in, as `hreflang` alternates, from the host's
 * {@see LocalizedPageUrlResolverInterface}. Source locale first.
 *
 * @see docs/guide/translation.md#hreflang-links
 */
final class AlternatePageLinks
{
    public function __construct(
        private readonly LocalizedPageUrlResolverInterface $urls,
        private readonly TranslationLocales $locales,
        private readonly RequestStack $requestStack,
    ) {
    }

    /**
     * @return list<array{locale: string, hreflang: string, url: string}>
     */
    public function forArea(ContentArea $area): array
    {
        $links = [];

        foreach ($this->locales->getAllLocales() as $locale) {
            $url = $this->urls->resolve($area, $locale);

            if ($url === null || $url === '') {
                continue;
            }

            $links[] = [
                'locale' => $locale,
                'hreflang' => self::hreflang($locale),
                'url' => $this->absolute($url),
            ];
        }

        return $links;
    }

    /** `pt_BR` is a PHP locale; `hreflang` wants the BCP 47 `pt-BR`. */
    public static function hreflang(string $locale): string
    {
        return str_replace('_', '-', $locale);
    }

    // Search engines ignore a relative alternate; a resolver returns paths.
    private function absolute(string $url): string
    {
        $request = $this->requestStack->getMainRequest();

        if ($request === null || !str_starts_with($url, '/')) {
            return $url;
        }

        if (str_starts_with($url, '//')) {
            return $request->getScheme() . ':' . $url;
        }

        return $request->getSchemeAndHttpHost() . $url;
    }
}
