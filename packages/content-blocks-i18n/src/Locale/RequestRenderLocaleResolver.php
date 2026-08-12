<?php

declare(strict_types=1);

namespace ContentBlocks\I18n\Locale;

use ContentBlocks\Rendering\RenderContext;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Default {@see RenderLocaleResolverInterface}: an explicit context locale wins,
 * otherwise the current request's.
 *
 * The precedence is the important part. A language switcher, a sitemap job and a
 * transactional email all pass a locale explicitly and must get it regardless of
 * what the ambient request says; everything else — an ordinary page render —
 * should simply follow `_locale`, which is what Symfony's locale listener has
 * already negotiated by the time a template renders a content area.
 *
 * An unknown locale (not in `content_blocks_i18n.locales`) resolves to null
 * rather than to itself: a typo'd `_locale` then renders the source text instead
 * of hunting for translation rows that cannot exist.
 */
final class RequestRenderLocaleResolver implements RenderLocaleResolverInterface
{
    public function __construct(
        private readonly RequestStack $requestStack,
        private readonly TranslationLocales $locales,
    ) {
    }

    public function resolve(RenderContext $context): ?string
    {
        $locale = $context->locale ?? $this->requestStack->getCurrentRequest()?->getLocale();

        if ($locale === null || !$this->locales->isTarget($locale)) {
            return null;
        }

        return $locale;
    }
}
