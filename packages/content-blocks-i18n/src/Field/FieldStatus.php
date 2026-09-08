<?php

declare(strict_types=1);

namespace ContentBlocks\I18n\Field;

/**
 * Where one translatable field stands in one locale. Three states, because two
 * would hide the expensive one.
 *
 * @see docs/internals/i18n.md#three-states-not-two
 */
enum FieldStatus: string
{
    /** No value stored for this locale; the render falls back to the source. */
    case MISSING = 'missing';

    /** Stored, and the source still hashes to what it did. */
    case TRANSLATED = 'translated';

    /**
     * Stored, but the source changed afterwards. Still rendered — a stale
     * translation beats an English paragraph on a German page.
     */
    case OUTDATED = 'outdated';

    /** Counts toward "done" in a progress figure. */
    public function isDone(): bool
    {
        return $this === self::TRANSLATED;
    }
}
