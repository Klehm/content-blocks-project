<?php

declare(strict_types=1);

namespace ContentBlocks\Builder;

/**
 * What an editor may do to the sections and columns of one area. Blocks are
 * always editable; this only narrows the structure around them.
 *
 * @see docs/guide/builder-structure.md
 */
final class BuilderStructure
{
    /** Sections are added, moved, duplicated and deleted freely. */
    public const SECTIONS_EDITABLE = 'editable';
    /** The sections in place stay; their settings remain editable. */
    public const SECTIONS_FIXED = 'fixed';
    /** No section in the UI: blocks stack in one implicit section. */
    public const SECTIONS_HIDDEN = 'hidden';

    public const SECTIONS = [self::SECTIONS_EDITABLE, self::SECTIONS_FIXED, self::SECTIONS_HIDDEN];

    public function __construct(
        public readonly string $sections = self::SECTIONS_EDITABLE,
        public readonly bool $columns = true,
    ) {
        if (!\in_array($sections, self::SECTIONS, true)) {
            $expected = implode(', ', self::SECTIONS);

            throw new \InvalidArgumentException(sprintf('Unknown sections mode "%s"; expected: %s.', $sections, $expected));
        }
    }

    /** Adding, moving, duplicating, deleting and pasting sections. */
    public function canEditSections(): bool
    {
        return $this->sections === self::SECTIONS_EDITABLE;
    }

    /** Sections can be selected, and their settings sidebar opened. */
    public function showsSections(): bool
    {
        return $this->sections !== self::SECTIONS_HIDDEN;
    }

    /** Adding, removing and naming columns, and laying them out. */
    public function canEditColumns(): bool
    {
        return $this->columns && $this->showsSections();
    }

    /** @return array{sections: string, columns: bool} */
    public function toArray(): array
    {
        return ['sections' => $this->sections, 'columns' => $this->canEditColumns()];
    }
}
