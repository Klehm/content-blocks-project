<?php

declare(strict_types=1);

namespace ContentBlocks\Clipboard;

/**
 * What {@see BlockDataReplayer} made of one clipboard block: the data it is
 * safe to write, and the field names that did not survive the replay.
 */
final class BlockDataReplayResult
{
    /**
     * @param array<string, mixed> $data          ready to write to the block's draft
     * @param list<string>         $droppedFields fields reset to the type's default, in payload order
     */
    public function __construct(
        public readonly array $data,
        public readonly array $droppedFields,
    ) {
    }
}
