<?php

declare(strict_types=1);

namespace ContentBlocks\Twig;

use ContentBlocks\Entity\ContentArea;
use ContentBlocks\Palette\ColorPaletteRegistry;
use ContentBlocks\Preview\ContentAreaUrlResolverInterface;
use ContentBlocks\Rendering\BlockRendererInterface;
use ContentBlocks\Rendering\RenderContext;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class ContentBlocksExtension extends AbstractExtension
{
    public function __construct(
        private readonly BlockRendererInterface $renderer,
        private readonly ContentAreaUrlResolverInterface $urlResolver,
        private readonly ColorPaletteRegistry $palette,
    ) {
    }

    /**
     * @return list<TwigFunction>
     */
    public function getFunctions(): array
    {
        return [
            new TwigFunction(
                'cb_render_content_area',
                [$this, 'renderContentArea'],
                ['is_safe' => ['html']],
            ),
            new TwigFunction(
                'cb_preview_url',
                [$this, 'previewUrl'],
            ),
            new TwigFunction(
                'cb_color_palette',
                [$this, 'colorPalette'],
            ),
        ];
    }

    /**
     * The configured palette, for handing the same named colors to a JS widget
     * — a rich-text editor's swatches, say.
     *
     * @return list<array{label: string, color: string}>
     */
    public function colorPalette(): array
    {
        $out = [];
        foreach ($this->palette->all() as $color) {
            $out[] = ['label' => $color->label, 'color' => $color->color];
        }

        return $out;
    }

    /**
     * Mode is auto-detected from the request. `$locale` pins the language only
     * — mode detection is unaffected, and with no i18n package it is inert.
     *
     * @see docs/internals/rendering.md#why-the-pipeline-takes-a-context-object
     */
    public function renderContentArea(?ContentArea $area, ?string $locale = null): string
    {
        if ($area === null) {
            return '';
        }

        return $this->renderer->render($area, RenderContext::forLocale($locale));
    }

    /**
     * The host's public URL for the owning page, with `?cb_preview=1` appended
     * so the renderer serves draft state.
     */
    public function previewUrl(ContentArea $area): string
    {
        $url = $this->urlResolver->resolve($area);
        $separator = str_contains($url, '?') ? '&' : '?';

        return $url . $separator . BlockRendererInterface::QUERY_PARAM . '=1';
    }
}
