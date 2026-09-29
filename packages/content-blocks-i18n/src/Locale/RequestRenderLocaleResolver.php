<?php

declare(strict_types=1);

namespace ContentBlocks\I18n\Locale;

use ContentBlocks\I18n\Preview\PreviewLocaleListener;
use ContentBlocks\Rendering\RenderContext;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Default resolver: the context locale, else the workbench preview's, else the
 * request's. An unknown locale resolves to null: a typo renders the source.
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
        $pinned = $this->requestStack->getMainRequest()?->attributes->get(PreviewLocaleListener::ATTRIBUTE);

        $locale = $context->locale
            ?? (\is_string($pinned) ? $pinned : null)
            ?? $this->requestStack->getCurrentRequest()?->getLocale();

        if ($locale === null || !$this->locales->isTarget($locale)) {
            return null;
        }

        return $locale;
    }
}
