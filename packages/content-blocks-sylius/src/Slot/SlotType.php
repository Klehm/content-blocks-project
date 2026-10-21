<?php

declare(strict_types=1);

namespace ContentBlocks\Sylius\Slot;

/** The native shop pages a content slot attaches to. */
final class SlotType
{
    public const HOMEPAGE = 'homepage';
    public const PRODUCT = 'product';
    public const TAXON = 'taxon';

    public const ALL = [self::HOMEPAGE, self::PRODUCT, self::TAXON];

    private function __construct()
    {
    }
}
