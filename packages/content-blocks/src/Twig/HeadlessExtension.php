<?php

declare(strict_types=1);

namespace ContentBlocks\Twig;

use ContentBlocks\Entity\ContentArea;
use ContentBlocks\Headless\ContentAreaNormalizerInterface;
use ContentBlocks\Rendering\RenderContext;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * `cb_content_area_data()` and `cb_content_area_json()`: the area as data, for
 * a template that hands it to a JS front.
 *
 * @see docs/guide/headless.md
 */
final class HeadlessExtension extends AbstractExtension
{
    public function __construct(
        private readonly ContentAreaNormalizerInterface $normalizer,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('cb_content_area_data', $this->data(...)),
            new TwigFunction('cb_content_area_json', $this->json(...), ['is_safe' => ['html']]),
        ];
    }

    /** @return array<string, mixed>|null */
    public function data(?ContentArea $area, ?string $locale = null): ?array
    {
        return $area === null ? null : $this->normalizer->normalize($area, RenderContext::forLocale($locale));
    }

    public function json(?ContentArea $area, ?string $locale = null): string
    {
        return $area === null ? 'null' : $this->normalizer->toJson($area, RenderContext::forLocale($locale));
    }
}
