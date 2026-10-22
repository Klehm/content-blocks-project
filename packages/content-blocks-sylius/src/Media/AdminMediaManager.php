<?php

declare(strict_types=1);

namespace ContentBlocks\Sylius\Media;

use ContentBlocks\Media\Security\MediaManagerInterface;
use ContentBlocks\Sylius\Security\AdminVisitor;

/**
 * A shop administrator manages the media library, as they edit every area.
 * Used only with klehm/content-blocks-media, unless the host aliases its own.
 */
final class AdminMediaManager implements MediaManagerInterface
{
    public function __construct(private readonly AdminVisitor $visitor)
    {
    }

    public function canManage(): bool
    {
        return $this->visitor->isAdmin();
    }
}
