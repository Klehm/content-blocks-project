<?php

declare(strict_types=1);

namespace ContentBlocks\I18n\Rendering;

use ContentBlocks\Entity\Block;
use ContentBlocks\Entity\ContentArea;
use ContentBlocks\Entity\Section;
use ContentBlocks\I18n\Locale\RenderLocaleResolverInterface;
use ContentBlocks\I18n\Storage\TranslationStore;
use ContentBlocks\Rendering\BlockRendererInterface;
use ContentBlocks\Rendering\RenderContext;
use ContentBlocks\Rendering\RenderMode;

/**
 * Decorates the renderer to load an area's translations in one query before the
 * resolver pipeline starts asking for them block by block.
 *
 * A decorator rather than a hook in the core: the spike deliberately did not add
 * a prefetch seam, because `BlockRendererInterface` is already aliased and
 * decorating it is the idiom. The core stays unaware that anything needs warming.
 *
 * It only *warms a cache* — correctness never depends on it. Every method here
 * could be reduced to a bare delegation and the page would still render
 * correctly, just with one SELECT per block. That is the property that makes it
 * safe for a host to re-decorate or replace the renderer without knowing this
 * class exists.
 */
final class PrefetchingBlockRenderer implements BlockRendererInterface
{
    public function __construct(
        private readonly BlockRendererInterface $inner,
        private readonly TranslationStore $store,
        private readonly RenderLocaleResolverInterface $localeResolver,
    ) {
    }

    public function render(ContentArea $area, ?RenderContext $context = null): string
    {
        $this->warm($area, $context);

        return $this->inner->render($area, $context);
    }

    public function resolveMode(ContentArea $area): RenderMode
    {
        return $this->inner->resolveMode($area);
    }

    public function renderBlock(Block $block, ?RenderContext $context = null): string
    {
        // One block, one lookup — warming its whole area here would load dozens
        // of rows to use one. This is the builder's hot-swap path after an
        // inline edit, so it runs often and touches little.
        return $this->inner->renderBlock($block, $context);
    }

    public function renderSection(Section $section, ?RenderContext $context = null): string
    {
        $this->warm($section->getContentArea(), $context);

        return $this->inner->renderSection($section, $context);
    }

    private function warm(?ContentArea $area, ?RenderContext $context): void
    {
        if ($area === null) {
            return;
        }

        $locale = $this->localeResolver->resolve($context ?? new RenderContext());

        if ($locale !== null) {
            $this->store->prefetchArea($area, $locale);
        }
    }
}
