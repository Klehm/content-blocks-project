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
     * Draft data, soft-deleted blocks kept with a marker for the overlay JS,
     * ordered by previewPosition.
     */
    case PREVIEW = 'preview';
}
