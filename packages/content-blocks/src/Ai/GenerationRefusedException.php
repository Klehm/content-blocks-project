<?php

declare(strict_types=1);

namespace ContentBlocks\Ai;

/**
 * Generated content that cannot be written: nothing usable in it, too much of
 * it, or an area whose structure takes none.
 */
final class GenerationRefusedException extends \RuntimeException
{
    /** Not a `{"sections": [...]}` document. */
    public const UNREADABLE = 'unreadable';
    /** No section, or no block survived the replay. */
    public const EMPTY = 'empty';
    /** More than a page could hold. */
    public const TOO_LARGE = 'too_large';
    /** The area's sections are fixed: nothing can be added. */
    public const SECTIONS_LOCKED = 'sections_locked';
    /** Blocks only, and no column to put them in. */
    public const NO_TARGET = 'no_target';

    public function __construct(public readonly string $reason)
    {
        parent::__construct(sprintf('Generated content refused: %s.', $reason));
    }
}
