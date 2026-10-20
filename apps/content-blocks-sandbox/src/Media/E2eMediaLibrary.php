<?php

declare(strict_types=1);

namespace App\Media;

use ContentBlocks\Storage\MediaLibraryInterface;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\DependencyInjection\Attribute\AutowireDecorated;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * The package's library, unless the `cb_e2e_media` cookie names the kinds
 * (comma-separated, `none` for none) so Playwright can pin the core button.
 */
#[AsDecorator(MediaLibraryInterface::class)]
final class E2eMediaLibrary implements MediaLibraryInterface
{
    public const COOKIE = 'cb_e2e_media';

    public function __construct(
        #[AutowireDecorated]
        private readonly MediaLibraryInterface $inner,
        private readonly RequestStack $requestStack,
        #[Autowire('%kernel.debug%')]
        private readonly bool $debug,
    ) {
    }

    public function supports(string $kind): bool
    {
        $cookie = $this->requestStack->getMainRequest()?->cookies->get(self::COOKIE);
        if (!$this->debug || !\is_string($cookie)) {
            return $this->inner->supports($kind);
        }

        return \in_array($kind, explode(',', $cookie), true);
    }
}
