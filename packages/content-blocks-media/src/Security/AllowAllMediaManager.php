<?php

declare(strict_types=1);

namespace ContentBlocks\Media\Security;

/** Every editor manages the library. For a host where editors are trusted. */
final class AllowAllMediaManager implements MediaManagerInterface
{
    public function canManage(): bool
    {
        return true;
    }
}
