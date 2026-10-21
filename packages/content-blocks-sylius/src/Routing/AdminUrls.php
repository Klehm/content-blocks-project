<?php

declare(strict_types=1);

namespace ContentBlocks\Sylius\Routing;

use ContentBlocks\Entity\ContentArea;
use ContentBlocks\Sylius\Entity\ContentPage;
use ContentBlocks\Sylius\Owner\AreaOwnerLocator;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/** The admin screen editing an area; the dashboard for a host's own area. */
final class AdminUrls
{
    public const SLOT_ROUTE = 'content_blocks_sylius_admin_slot';
    public const PAGE_UPDATE_ROUTE = 'content_blocks_sylius_admin_content_page_update';

    public function __construct(
        private readonly UrlGeneratorInterface $router,
        private readonly AreaOwnerLocator $owners,
    ) {
    }

    public function edit(ContentArea $area): string
    {
        $owner = $this->owners->locate($area);

        return match (true) {
            $owner instanceof ContentPage => $this->router->generate(self::PAGE_UPDATE_ROUTE, ['id' => $owner->getId()]),
            $owner !== null => $this->slot($owner->getType(), $owner->getSubject()),
            default => $this->router->generate('sylius_admin_dashboard'),
        };
    }

    public function slot(string $type, string $subject): string
    {
        return $this->router->generate(self::SLOT_ROUTE, ['type' => $type, 'subject' => $subject]);
    }
}
