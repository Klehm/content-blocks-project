<?php

declare(strict_types=1);

namespace ContentBlocks\History;

use ContentBlocks\Entity\ActionLogEntry;
use ContentBlocks\Entity\ContentArea;

/**
 * The draft-scoped action history: records what a builder mutation moved, and
 * puts it back by replaying the inverse.
 *
 * @see docs/internals/history.md
 *
 * @internal the routes and the recorded ops are the contract, not this class
 */
final class ActionJournal
{
    /** A pause longer than this ends a coalesced run. */
    public const COALESCE_IDLE_SECONDS = 10;
    /** And a run never grows past this, however steadily it is fed. */
    public const COALESCE_SPAN_SECONDS = 60;
    /** Entries kept per (area, session). */
    public const KEEP_ENTRIES = 100;
    /** Rows of a session that never published are swept after this. */
    public const RETENTION_DAYS = 7;

    public function __construct(
        private readonly ActionLogStoreInterface $store,
        private readonly StateApplier $applier,
        private readonly BuilderSession $session,
    ) {
    }

    /**
     * Runs `$mutation` between two reads of `$scope` and journals what moved.
     * A mutation that moved nothing records nothing.
     *
     * @see docs/internals/history.md#recording-is-a-wrapper-not-a-call-site
     *
     * @template T
     *
     * @param callable(): T $mutation
     *
     * @return T
     */
    public function record(
        ContentArea $area,
        string $label,
        JournalScope $scope,
        callable $mutation,
        ?string $coalesceKey = null,
    ): mixed {
        $sessionId = $this->session->id();
        if ($sessionId === null) {
            return $mutation();
        }

        $before = $scope->capture($area);
        $result = $mutation();
        $delta = $before->diff($scope->capture($area));

        if (!$delta->complete) {
            // A row left the draft, so nothing older can be replayed either.
            $this->store->clear($area);
            $this->store->save();

            return $result;
        }

        if (!$delta->isEmpty()) {
            $this->append($area, $sessionId, $label, $delta, $coalesceKey);
        }

        return $result;
    }

    public function undo(ContentArea $area): HistoryResult
    {
        $sessionId = $this->session->id();
        if ($sessionId === null) {
            return new HistoryResult(HistoryResult::STATUS_UNAVAILABLE);
        }

        $entry = $this->store->top($area, $sessionId);
        if ($entry === null) {
            return $this->outcome($area, $sessionId, HistoryResult::STATUS_NOTHING);
        }

        // Refused rather than guessed: something moved under this entry.
        if (!$this->applier->matches($entry->getRedoOps())) {
            return $this->outcome($area, $sessionId, HistoryResult::STATUS_STALE);
        }

        $ops = $entry->getUndoOps();
        $this->applier->apply($ops);
        $entry->setUndone(true);
        $this->store->save();

        return $this->outcome($area, $sessionId, HistoryResult::STATUS_OK, $entry->getLabel(), $ops);
    }

    public function redo(ContentArea $area): HistoryResult
    {
        $sessionId = $this->session->id();
        if ($sessionId === null) {
            return new HistoryResult(HistoryResult::STATUS_UNAVAILABLE);
        }

        $entry = $this->store->firstUndone($area, $sessionId);
        if ($entry === null) {
            return $this->outcome($area, $sessionId, HistoryResult::STATUS_NOTHING);
        }

        if (!$this->applier->matches($entry->getUndoOps())) {
            return $this->outcome($area, $sessionId, HistoryResult::STATUS_STALE);
        }

        $ops = $entry->getRedoOps();
        $this->applier->apply($ops);
        $entry->setUndone(false);
        $this->store->save();

        return $this->outcome($area, $sessionId, HistoryResult::STATUS_OK, $entry->getLabel(), $ops);
    }

    /**
     * What the topbar's two buttons start out as. Read-only: the client keeps
     * them in step itself afterwards.
     *
     * @see docs/internals/history.md#the-buttons-and-their-state
     *
     * @return array{canUndo: bool, canRedo: bool}
     */
    public function state(ContentArea $area): array
    {
        $sessionId = $this->session->id();
        if ($sessionId === null) {
            return ['canUndo' => false, 'canRedo' => false];
        }

        return [
            'canUndo' => $this->store->top($area, $sessionId) !== null,
            'canRedo' => $this->store->firstUndone($area, $sessionId) !== null,
        ];
    }

    /**
     * Publish removes soft-deleted rows and discard reverts every draft field,
     * so every entry is either dangling or wrong afterwards.
     */
    public function forget(ContentArea $area): void
    {
        $this->store->clear($area);
        $this->store->save();
    }

    /**
     * @see docs/internals/history.md#coalescing-a-run-of-typing
     */
    private function append(
        ContentArea $area,
        string $sessionId,
        string $label,
        ActionDelta $delta,
        ?string $coalesceKey,
    ): void {
        // A new action is a new future: whatever was undone cannot come back.
        $this->store->dropUndone($area, $sessionId);

        $now = new \DateTimeImmutable();
        $top = $this->store->top($area, $sessionId);

        if ($coalesceKey !== null && $top !== null && $this->mergeable($top, $coalesceKey, $now)) {
            // The run keeps its original "before"; only its end moves.
            $top->setRedoOps($delta->redo);
            $top->setUpdatedAt($now);
            $this->store->save();

            return;
        }

        $entry = (new ActionLogEntry())
            ->setContentArea($area)
            ->setSessionId($sessionId)
            ->setSeq(($top?->getSeq() ?? 0) + 1)
            ->setLabel($label)
            ->setCoalesceKey($coalesceKey)
            ->setUndoOps($delta->undo)
            ->setRedoOps($delta->redo)
            ->setCreatedAt($now)
            ->setUpdatedAt($now);

        $this->store->add($entry);
        $this->store->save();

        $this->store->prune(
            $area,
            $sessionId,
            self::KEEP_ENTRIES,
            $now->modify('-' . self::RETENTION_DAYS . ' days'),
        );
    }

    private function mergeable(ActionLogEntry $top, string $coalesceKey, \DateTimeImmutable $now): bool
    {
        if ($top->getCoalesceKey() !== $coalesceKey) {
            return false;
        }

        $idle = $now->getTimestamp() - $top->getUpdatedAt()->getTimestamp();
        $span = $now->getTimestamp() - $top->getCreatedAt()->getTimestamp();

        return $idle <= self::COALESCE_IDLE_SECONDS && $span <= self::COALESCE_SPAN_SECONDS;
    }

    /**
     * @param list<array<string, mixed>> $appliedOps
     */
    private function outcome(
        ContentArea $area,
        string $sessionId,
        string $status,
        ?string $label = null,
        array $appliedOps = [],
    ): HistoryResult {
        return new HistoryResult(
            $status,
            $label,
            $this->store->top($area, $sessionId) !== null,
            $this->store->firstUndone($area, $sessionId) !== null,
            $appliedOps,
        );
    }
}
