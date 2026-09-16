<?php

declare(strict_types=1);

namespace ContentBlocks\Twig;

use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * `cb_api_base()`: where the host mounted the builder's endpoints, read from
 * the router so the builder's JS never spells `/_content-blocks` itself.
 *
 * @see docs/internals/frontend.md#endpoint-urls-come-from-the-router
 */
final class RoutingExtension extends AbstractExtension
{
    /** Any route of `routes/editor.php` would do; this one takes no args. */
    private const ANCHOR_ROUTE = 'content_blocks_block_types';
    private const ANCHOR_PATH = '/types';

    public function __construct(
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    /**
     * @return list<TwigFunction>
     */
    public function getFunctions(): array
    {
        return [
            new TwigFunction('cb_api_base', [$this, 'apiBase']),
        ];
    }

    /**
     * No trailing slash, and the app's base URL included — an app served from
     * a subdirectory gets it for free.
     */
    public function apiBase(): string
    {
        $anchor = $this->urlGenerator->generate(self::ANCHOR_ROUTE);

        if (!str_ends_with($anchor, self::ANCHOR_PATH)) {
            $message = 'Route "%s" resolves to "%s": the builder endpoints must '
                . 'be imported together, from config/routes/editor.php.';

            throw new \LogicException(sprintf($message, self::ANCHOR_ROUTE, $anchor));
        }

        return substr($anchor, 0, -\strlen(self::ANCHOR_PATH));
    }
}
