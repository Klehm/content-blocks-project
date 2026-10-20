<?php

declare(strict_types=1);

namespace ContentBlocks\Media\Security;

/**
 * Authorizes managing the shared library: folders, titles and alt texts,
 * moves, removal. Browsing and uploading need only the edit right.
 *
 * @see docs/internals/media.md#who-may-browse
 */
interface MediaManagerInterface
{
    public function canManage(): bool;
}
