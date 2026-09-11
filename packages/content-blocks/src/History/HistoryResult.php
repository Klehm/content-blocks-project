<?php

declare(strict_types=1);

namespace ContentBlocks\History;

/**
 * The outcome of an undo or a redo, including the two flags the topbar and
 * the snackbar read.
 *
 * @see docs/internals/history.md#an-entry-is-applied-or-refused
 *
 * @internal
 */
final class HistoryResult
{
    /** Applied. */
    public const STATUS_OK = 'ok';
    /** Nothing left on the stack in that direction. */
    public const STATUS_NOTHING = 'nothing';
    /** The draft no longer matches what the entry produced. */
    public const STATUS_STALE = 'stale';
    /** No session, so no stack could be kept in the first place. */
    public const STATUS_UNAVAILABLE = 'unavailable';

    /**
     * `$appliedOps` stays server-side: {@see SidebarOutcome} reads it, the
     * JSON carries only its verdict.
     *
     * @param list<array<string, mixed>> $appliedOps
     */
    public function __construct(
        public readonly string $status,
        public readonly ?string $label = null,
        public readonly bool $canUndo = false,
        public readonly bool $canRedo = false,
        public readonly array $appliedOps = [],
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'label' => $this->label,
            'canUndo' => $this->canUndo,
            'canRedo' => $this->canRedo,
        ];
    }
}
