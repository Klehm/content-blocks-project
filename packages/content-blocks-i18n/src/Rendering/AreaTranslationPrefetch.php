<?php

declare(strict_types=1);

namespace ContentBlocks\I18n\Rendering;

use ContentBlocks\Entity\ContentArea;
use ContentBlocks\I18n\Locale\RenderLocaleResolverInterface;
use ContentBlocks\I18n\Locale\TranslationLocales;
use ContentBlocks\I18n\Storage\TranslationStore;
use ContentBlocks\Rendering\RenderContext;

/**
 * One query per locale of the chain for a whole area, ahead of a render that
 * would otherwise ask block by block. Shared by the HTML and data renders.
 *
 * @internal
 */
final class AreaTranslationPrefetch
{
    public function __construct(
        private readonly TranslationStore $store,
        private readonly RenderLocaleResolverInterface $localeResolver,
        private readonly ?TranslationLocales $locales = null,
    ) {
    }

    public function warm(?ContentArea $area, ?RenderContext $context): void
    {
        if ($area === null) {
            return;
        }

        $locale = $this->localeResolver->resolve($context ?? new RenderContext());

        if ($locale === null) {
            return;
        }

        foreach ([$locale, ...$this->locales?->getFallbacks($locale) ?? []] as $candidate) {
            $this->store->prefetchArea($area, $candidate);
        }
    }
}
