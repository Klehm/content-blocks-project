<?php

declare(strict_types=1);

namespace ContentBlocks\Media\Library;

use ContentBlocks\Storage\MediaLibraryInterface;

/**
 * The package's answer to the core's *is there a library?*: yes, for the
 * kinds `content_blocks_media.kinds` lists.
 */
final class MediaLibrary implements MediaLibraryInterface
{
    /** @param list<string> $kinds */
    public function __construct(private readonly array $kinds)
    {
    }

    public function supports(string $kind): bool
    {
        return \in_array($kind, $this->kinds, true);
    }
}
