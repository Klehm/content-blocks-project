<?php

declare(strict_types=1);

namespace ContentBlocks\Rendering;

enum RenderMode: string
{
    /**
     * Public render: only published, non-deleted content, ordered by position.
     */
    case PUBLIC = 'public';

    /**
     * Preview render: includes draft data, soft-deleted blocks (with marker),
     * ordered by previewPosition. Markers are emitted for the overlay JS to
     * latch on.
     */
    case PREVIEW = 'preview';
}
