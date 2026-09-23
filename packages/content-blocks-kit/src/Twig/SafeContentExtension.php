<?php

declare(strict_types=1);

namespace ContentBlocks\Kit\Twig;

use ContentBlocks\Kit\Security\SafeLink;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

/**
 * Render-time guards for stored content: links and editor HTML may arrive by
 * import, template or translation, none of which runs the block's form.
 *
 * @see docs/guide/security.md#editor-html-and-links
 */
final class SafeContentExtension extends AbstractExtension
{
    public function __construct(
        private readonly HtmlSanitizerInterface $richTextSanitizer,
    ) {
    }

    public function getFilters(): array
    {
        return [
            new TwigFilter('cb_kit_safe_url', SafeLink::filter(...)),
            new TwigFilter('cb_kit_rich_html', $this->sanitize(...), ['is_safe' => ['html']]),
        ];
    }

    public function sanitize(mixed $html): string
    {
        return \is_string($html) ? $this->richTextSanitizer->sanitize($html) : '';
    }
}
