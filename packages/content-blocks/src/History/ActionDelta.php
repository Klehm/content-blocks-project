<?php

declare(strict_types=1);

namespace ContentBlocks\History;

/**
 * One action's two directions, as state assignments.
 *
 * @see docs/internals/history.md#what-an-entry-holds
 *
 * @internal
 */
final class ActionDelta
{
    /**
     * `$complete` is false when a row left the draft between the two reads.
     *
     * @param list<array{t: string, id: int, set: array<string, mixed>}> $undo
     * @param list<array{t: string, id: int, set: array<string, mixed>}> $redo
     */
    public function __construct(
        public readonly array $undo,
        public readonly array $redo,
        public readonly bool $complete,
    ) {
    }

    public static function incomplete(): self
    {
        return new self([], [], false);
    }

    public function isEmpty(): bool
    {
        return $this->undo === [] && $this->redo === [];
    }
}
