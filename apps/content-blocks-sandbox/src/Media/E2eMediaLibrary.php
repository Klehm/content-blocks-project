<?php

declare(strict_types=1);

namespace App\Media;

use ContentBlocks\Storage\MediaLibraryInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * A library for the kinds the `cb_e2e_media` cookie names (comma-separated),
 * so Playwright can pin the core's browse button. Guarded on kernel.debug.
 */
final class E2eMediaLibrary implements MediaLibraryInterface
{
    public const COOKIE = 'cb_e2e_media';

    public function __construct(
        private readonly RequestStack $requestStack,
        #[Autowire('%kernel.debug%')]
        private readonly bool $debug,
    ) {
    }

    public function supports(string $kind): bool
    {
        if (!$this->debug) {
            return false;
        }
        $cookie = (string) $this->requestStack->getMainRequest()?->cookies->get(self::COOKIE);

        return \in_array($kind, explode(',', $cookie), true);
    }
}
