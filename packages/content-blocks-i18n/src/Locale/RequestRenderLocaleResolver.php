<?php

declare(strict_types=1);

namespace ContentBlocks\I18n\Locale;

use ContentBlocks\Rendering\RenderContext;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Default resolver: an explicit context locale wins, else the request's. An
 * unknown locale resolves to null, so a typo renders the source text.
 *
 * @see docs/internals/i18n.md#config-and-mounting
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
