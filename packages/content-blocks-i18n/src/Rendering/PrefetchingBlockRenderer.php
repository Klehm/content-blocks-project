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
 * Loads an area's translations in one query before the pipeline asks block by
 * block. It only **warms a cache** — correctness never depends on it.
 *
 * @see docs/internals/i18n.md#why-a-side-table-not-an-envelope-in-blockdata
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
        // One block, one lookup: this is the hot-swap path, so it runs often
        // and warming the whole area would load dozens of rows to use one.
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
