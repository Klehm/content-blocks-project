<?php

declare(strict_types=1);

namespace ContentBlocks\I18n\Rendering;

use ContentBlocks\Entity\Block;
use ContentBlocks\Entity\ContentArea;
use ContentBlocks\Entity\Section;
use ContentBlocks\Headless\ContentAreaNormalizerInterface;
use ContentBlocks\Rendering\RenderContext;

/**
 * {@see PrefetchingBlockRenderer} for the data render: it warms the store, so
 * correctness never depends on it.
 */
final class PrefetchingContentAreaNormalizer implements ContentAreaNormalizerInterface
{
    public function __construct(
        private readonly ContentAreaNormalizerInterface $inner,
        private readonly AreaTranslationPrefetch $prefetch,
    ) {
    }

    public function normalize(ContentArea $area, ?RenderContext $context = null): array
    {
        $this->prefetch->warm($area, $context);

        return $this->inner->normalize($area, $context);
    }

    public function normalizeSection(Section $section, ?RenderContext $context = null): ?array
    {
        $this->prefetch->warm($section->getContentArea(), $context);

        return $this->inner->normalizeSection($section, $context);
    }

    public function normalizeBlock(Block $block, ?RenderContext $context = null): ?array
    {
        return $this->inner->normalizeBlock($block, $context);
    }

    public function toJson(ContentArea $area, ?RenderContext $context = null): string
    {
        $this->prefetch->warm($area, $context);

        return $this->inner->toJson($area, $context);
    }
}
