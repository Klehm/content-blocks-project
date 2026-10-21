<?php

declare(strict_types=1);

namespace ContentBlocks\Media\Security;

/** The default: nobody manages the library until the host says who may. */
final class DenyAllMediaManager implements MediaManagerInterface
{
    public function canManage(): bool
    {
        return false;
    }
}
