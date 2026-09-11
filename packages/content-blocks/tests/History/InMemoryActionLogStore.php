<?php

declare(strict_types=1);

namespace ContentBlocks\Tests\History;

use ContentBlocks\Entity\ActionLogEntry;
use ContentBlocks\Entity\ContentArea;
use ContentBlocks\History\ActionLogStoreInterface;

/**
 * The stack, kept in a list. Lets the journal's policy be tested without a
 * database, which is the whole reason the store is an interface.
 */
final class InMemoryActionLogStore implements ActionLogStoreInterface
{
    /** @var list<ActionLogEntry> */
    public array $entries = [];

    public int $saves = 0;

    public function top(ContentArea $area, string $sessionId): ?ActionLogEntry
    {
        $candidates = $this->stack($area, $sessionId, undone: false);
        usort($candidates, fn (ActionLogEntry $a, ActionLogEntry $b) => $b->getSeq() <=> $a->getSeq());

        return $candidates[0] ?? null;
    }

    public function firstUndone(ContentArea $area, string $sessionId): ?ActionLogEntry
    {
        $candidates = $this->stack($area, $sessionId, undone: true);
        usort($candidates, fn (ActionLogEntry $a, ActionLogEntry $b) => $a->getSeq() <=> $b->getSeq());

        return $candidates[0] ?? null;
    }

    public function add(ActionLogEntry $entry): void
    {
        $this->entries[] = $entry;
    }

    public function save(): void
    {
        ++$this->saves;
    }

    public function dropUndone(ContentArea $area, string $sessionId): void
    {
        $this->keep(fn (ActionLogEntry $e) => !(
            $e->getContentArea() === $area && $e->getSessionId() === $sessionId && $e->isUndone()
        ));
    }

    public function clear(ContentArea $area): void
    {
        $this->keep(fn (ActionLogEntry $e) => $e->getContentArea() !== $area);
    }

    public function prune(ContentArea $area, string $sessionId, int $keep, \DateTimeImmutable $before): void
    {
        $newest = $this->top($area, $sessionId);
        $cutoff = $newest === null ? null : $newest->getSeq() - $keep;

        $this->keep(function (ActionLogEntry $e) use ($area, $sessionId, $cutoff, $before) {
            if ($e->getContentArea() !== $area) {
                return true;
            }
            if ($e->getUpdatedAt() < $before) {
                return false;
            }

            return !($cutoff !== null && $e->getSessionId() === $sessionId && $e->getSeq() <= $cutoff);
        });
    }

    /** @return list<ActionLogEntry> */
    private function stack(ContentArea $area, string $sessionId, bool $undone): array
    {
        return array_values(array_filter(
            $this->entries,
            fn (ActionLogEntry $e) => $e->getContentArea() === $area
                && $e->getSessionId() === $sessionId
                && $e->isUndone() === $undone,
        ));
    }

    /** @param callable(ActionLogEntry): bool $predicate */
    private function keep(callable $predicate): void
    {
        $this->entries = array_values(array_filter($this->entries, $predicate));
    }
}
