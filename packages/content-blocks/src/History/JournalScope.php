<?php

declare(strict_types=1);

namespace ContentBlocks\History;

use ContentBlocks\Entity\Block;
use ContentBlocks\Entity\ContentArea;
use ContentBlocks\Entity\Section;

/**
 * How much of the draft one recorded action is allowed to have moved.
 *
 * @see docs/internals/history.md#the-delta-is-a-targeted-read
 *
 * @internal
 */
final class JournalScope
{
    private function __construct(
        private readonly Block|Section|null $target,
    ) {
    }

    /** Every section, column and block of the area — where things sit. */
    public static function structure(): self
    {
        return new self(null);
    }

    public static function blockData(Block $block): self
    {
        return new self($block);
    }

    public static function sectionSettings(Section $section): self
    {
        return new self($section);
    }

    public function capture(ContentArea $area): AreaStateSnapshot
    {
        return match (true) {
            $this->target instanceof Block => AreaStateSnapshot::blockData($this->target),
            $this->target instanceof Section => AreaStateSnapshot::sectionSettings($this->target),
            default => AreaStateSnapshot::structure($area),
        };
    }
}
