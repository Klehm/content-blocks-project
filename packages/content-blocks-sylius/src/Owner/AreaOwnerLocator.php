<?php

declare(strict_types=1);

namespace ContentBlocks\Sylius\Owner;

use ContentBlocks\Entity\ContentArea;
use ContentBlocks\Sylius\Entity\ContentPage;
use ContentBlocks\Sylius\Entity\ContentSlot;
use ContentBlocks\Sylius\Repository\ContentPageRepository;
use ContentBlocks\Sylius\Repository\ContentSlotRepository;

/** The plugin entity an area belongs to, or null when the host owns it. */
final class AreaOwnerLocator
{
    public function __construct(
        private readonly ContentPageRepository $pages,
        private readonly ContentSlotRepository $slots,
    ) {
    }

    public function locate(ContentArea $area): ContentPage|ContentSlot|null
    {
        if ($area->getId() === null) {
            return null;
        }

        return $this->pages->findOneByContentArea($area) ?? $this->slots->findOneByContentArea($area);
    }
}
