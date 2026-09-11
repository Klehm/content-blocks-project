<?php

declare(strict_types=1);

namespace ContentBlocks\History;

use ContentBlocks\Entity\Block;
use ContentBlocks\Entity\Section;
use Doctrine\ORM\EntityManagerInterface;

/**
 * What an applied undo leaves the open sidebar to do, so the editor keeps
 * their place when the step that moved was somewhere else entirely.
 *
 * @see docs/internals/history.md#the-sidebar-survives-an-undo-when-it-can
 *
 * @internal
 */
final class SidebarOutcome
{
    /** Untouched: the form stays as it is, caret and all. */
    public const KEEP = 'keep';
    /** Its values moved under it, so it has to be refetched. */
    public const RELOAD = 'reload';
    /** Its row is gone; a stale form would save itself straight back. */
    public const CLOSE = 'close';

    /** The op fields a sidebar form actually renders. */
    private const FORM_FIELDS = ['data', 'settings', 'layout', 'preset'];

    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * @param list<array<string, mixed>> $appliedOps
     */
    public function decide(array $appliedOps, ?string $type, ?int $id): string
    {
        if ($id === null) {
            return self::KEEP;
        }

        return match ($type) {
            'block' => $this->forBlock($appliedOps, $id),
            'section' => $this->forSection($appliedOps, $id),
            default => self::KEEP,
        };
    }

    /**
     * A block whose column or section went back to deleted is off the page
     * too, even though no op names the block itself.
     *
     * @param list<array<string, mixed>> $appliedOps
     */
    private function forBlock(array $appliedOps, int $id): string
    {
        $block = $this->em->find(Block::class, $id);
        if (!$block instanceof Block || $block->isDeleted()) {
            return self::CLOSE;
        }

        $column = $block->getColumn();
        $section = $column?->getSection();
        if ($column === null || $column->isDeleted() || $section === null || $section->isDeleted()) {
            return self::CLOSE;
        }

        return $this->touchesForm($appliedOps, AreaStateSnapshot::T_BLOCK, $id)
            ? self::RELOAD
            : self::KEEP;
    }

    /**
     * @param list<array<string, mixed>> $appliedOps
     */
    private function forSection(array $appliedOps, int $id): string
    {
        $section = $this->em->find(Section::class, $id);
        if (!$section instanceof Section || $section->isDeleted()) {
            return self::CLOSE;
        }

        if ($this->touchesForm($appliedOps, AreaStateSnapshot::T_SECTION, $id)) {
            return self::RELOAD;
        }

        // `columnWidths` is one of the section form's own fields, so a column
        // preset moving is that form moving.
        foreach ($section->getColumns() as $column) {
            $columnId = $column->getId();
            if ($columnId !== null && $this->touchesForm($appliedOps, AreaStateSnapshot::T_COLUMN, $columnId)) {
                return self::RELOAD;
            }
        }

        return self::KEEP;
    }

    /**
     * @param list<array<string, mixed>> $appliedOps
     */
    private function touchesForm(array $appliedOps, string $type, int $id): bool
    {
        foreach ($appliedOps as $op) {
            if (($op['t'] ?? null) !== $type || ($op['id'] ?? null) !== $id) {
                continue;
            }

            /** @var array<string, mixed> $set */
            $set = \is_array($op['set'] ?? null) ? $op['set'] : [];
            foreach (self::FORM_FIELDS as $field) {
                if (\array_key_exists($field, $set)) {
                    return true;
                }
            }
        }

        return false;
    }
}
