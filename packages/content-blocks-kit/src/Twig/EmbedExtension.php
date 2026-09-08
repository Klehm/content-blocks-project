<?php

declare(strict_types=1);

namespace ContentBlocks\Kit\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Normalizes a YouTube or Vimeo URL into its player URL. Null for anything
 * unrecognized, so the view shows a hint rather than an empty iframe.
 */
final class EmbedExtension extends AbstractExtension
{
    public function getFunctions(): array
    {
        return [
            new TwigFunction('cb_embed_url', $this->buildEmbedUrl(...)),
        ];
    }

    public function buildEmbedUrl(string $url): ?string
    {
        $url = trim($url);

        // Bare 11-char YouTube id.
        if (preg_match('#^[A-Za-z0-9_-]{11}$#', $url) === 1) {
            return 'https://www.youtube.com/embed/' . $url;
        }

        if (preg_match('#(?:youtube\.com/(?:watch\?v=|embed/|shorts/)|youtu\.be/)([A-Za-z0-9_-]{11})#', $url, $m) === 1) {
            return 'https://www.youtube.com/embed/' . $m[1];
        }

        if (preg_match('#vimeo\.com/(?:video/)?(\d+)#', $url, $m) === 1) {
            return 'https://player.vimeo.com/video/' . $m[1];
        }

        return null;
    }
}
