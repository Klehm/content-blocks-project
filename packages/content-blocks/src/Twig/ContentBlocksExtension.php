<?php

declare(strict_types=1);

namespace ContentBlocks\Twig;

use ContentBlocks\Entity\ContentArea;
use ContentBlocks\Preview\ContentAreaUrlResolverInterface;
use ContentBlocks\Rendering\BlockRenderer;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class ContentBlocksExtension extends AbstractExtension
{
    public function __construct(
        private readonly BlockRenderer $renderer,
        private readonly ContentAreaUrlResolverInterface $urlResolver,
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
        ];
    }

    public function renderContentArea(ContentArea $area): string
    {
        return $this->renderer->render($area);
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

        return $url . $separator . BlockRenderer::QUERY_PARAM . '=1';
    }
}
