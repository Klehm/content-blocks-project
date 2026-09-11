<?php

declare(strict_types=1);

namespace ContentBlocks\History;

use ContentBlocks\Entity\ActionLogEntry;
use ContentBlocks\Entity\ContentArea;

/**
 * Where the undo stack lives. Separate from {@see ActionJournal} so the
 * policy is testable without a database.
 *
 * @see docs/internals/history.md#where-the-journal-lives
 *
 * @internal
 */
interface ActionLogStoreInterface
{
    /** Highest sequence not yet undone: the next candidate for undo. */
    public function top(ContentArea $area, string $sessionId): ?ActionLogEntry;

    /** Lowest sequence already undone: the most recently undone entry. */
    public function firstUndone(ContentArea $area, string $sessionId): ?ActionLogEntry;

    public function add(ActionLogEntry $entry): void;

    public function save(): void;

    /** Drops the redo tail, which a fresh action invalidates. */
    public function dropUndone(ContentArea $area, string $sessionId): void;

    /** Every session's entries: publish and discard invalidate them all. */
    public function clear(ContentArea $area): void;

    public function prune(ContentArea $area, string $sessionId, int $keep, \DateTimeImmutable $before): void;
}
