<?php

declare(strict_types=1);

namespace ContentBlocks\I18n\Field;

/**
 * Where one translatable field stands in one locale.
 *
 * Three states, because two would hide the expensive one. "Translated vs not"
 * is easy to compute and useless to an editorial team: the field that costs
 * money is the one that *was* translated and whose source has since been
 * rewritten, because nothing about the page looks wrong — the German is there,
 * it is simply describing last month's offer.
 */
enum FieldStatus: string
{
    /** No value stored for this locale; the render falls back to the source. */
    case MISSING = 'missing';

    /** Stored, and the source still hashes to what it did when it was written. */
    case TRANSLATED = 'translated';

    /**
     * Stored, but the source text changed afterwards. Still rendered — a stale
     * translation beats an English paragraph on a German page — and flagged so
     * an editor can decide.
     */
    case OUTDATED = 'outdated';

    /** Counts toward "done" in a progress figure. */
    public function isDone(): bool
    {
        return $this === self::TRANSLATED;
    }
}
