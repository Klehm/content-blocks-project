<?php

declare(strict_types=1);

namespace ContentBlocks\Twig;

use ContentBlocks\Entity\ContentArea;
use ContentBlocks\Rendering\BlockRenderer;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class ContentBlocksExtension extends AbstractExtension
{
    public function __construct(
        private readonly BlockRenderer $renderer,
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
        ];
    }

    public function renderContentArea(ContentArea $area): string
    {
        return $this->renderer->render($area);
    }
}
