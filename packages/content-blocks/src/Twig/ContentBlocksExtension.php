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
     * The configured color palette as a list of `{label, color}` maps —
     * handy for surfacing the same named colors a `PaletteColorType`
     * offers to a JS widget (e.g. a rich-text editor's color swatches).
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
     * `{{ cb_render_content_area(page.contentArea) }}` — mode is auto-detected
     * from the request (preview for an editor with access, public otherwise).
     *
     * Pass `locale` to pin the language a locale-aware
     * {@see \ContentBlocks\Rendering\BlockDataResolverInterface} should serve —
     * `{{ cb_render_content_area(page.contentArea, 'fr') }}`. It only sets the
     * language; mode detection is unaffected, so an editor previewing a
     * pinned-locale page still sees their draft. With no translation package
     * installed the argument is inert.
     */
    public function renderContentArea(?ContentArea $area, ?string $locale = null): string
    {
        if ($area === null) {
            return '';
        }

        return $this->renderer->render($area, RenderContext::forLocale($locale));
    }

    /**
     * Iframe-ready URL for previewing this ContentArea: the public URL the
     * host app exposes for the owning page, with `?cb_preview=1` appended so
     * BlockRenderer renders draft state.
     */
    public function previewUrl(ContentArea $area): string
    {
        $url = $this->urlResolver->resolve($area);
        $separator = str_contains($url, '?') ? '&' : '?';

        return $url . $separator . BlockRendererInterface::QUERY_PARAM . '=1';
    }
}
