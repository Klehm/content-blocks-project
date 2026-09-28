<?php

declare(strict_types=1);

namespace ContentBlocks\Event;

use ContentBlocks\Entity\Block;
use ContentBlocks\Entity\ContentArea;

/**
 * A block's sidebar form passed validation and `$data` is about to become
 * its draft. Refusing it keeps the draft and tells the editor why.
 *
 * @see docs/guide/events.md
 */
final class BeforeBlockSaveEvent extends RefusableEvent
{
    /**
     * @param array<string, mixed> $data the draft data about to be written
     */
    public function __construct(
        public readonly Block $block,
        public readonly ContentArea $area,
        public readonly array $data,
    ) {
    }
}
