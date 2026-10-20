<?php

declare(strict_types=1);

namespace ContentBlocks\Storage;

/**
 * Says a media library exists, so the upload widget offers *Browse library*.
 * The dialog itself answers the widget's `cb:media:browse` event.
 *
 * @see docs/guide/media-library.md
 */
interface MediaLibraryInterface
{
    /**
     * Whether the library offers files of this kind: `image` or `video`.
     * False hides the button on that field.
     */
    public function supports(string $kind): bool;
}
