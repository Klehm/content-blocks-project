<?php

declare(strict_types=1);

namespace ContentBlocks\Clipboard;

use ContentBlocks\BlockType\BlockTypeRegistry;
use ContentBlocks\Entity\Block;
use ContentBlocks\Entity\Column;
use ContentBlocks\Entity\ContentArea;
use ContentBlocks\Entity\Section;
use ContentBlocks\SectionTemplate\IncompatibleTemplateException;
use ContentBlocks\SectionTemplate\SectionTemplateInstantiatorInterface;

/**
 * Replays a clipboard payload into an area and places what comes out.
 *
 * Two halves, both deliberate:
 *
 *  - **Replay** reuses the section-template instantiator for the section scope
 *    (same payload format, same skip-unknown-types behavior) and adds the block
 *    scope one level down. Every block that survives then goes through
 *    {@see BlockDataReplayer}, which is what makes a `localStorage` payload safe
 *    to write.
 *  - **Placement** is the rule the editor sees: a pasted section lands right
 *    after the selected one (at the end of the area when nothing is selected),
 *    a pasted block right after the selected one (at the end of its column
 *    otherwise). Both re-index their siblings so `previewPosition` stays dense,
 *    the same convention Duplicate and Move follow.
 *
 * Entities come back attached to their parent but not flushed — persisting is
 * the controller's job, as everywhere else in the package.
 */
final class ClipboardPaster
{
    public function __construct(
        private readonly SectionTemplateInstantiatorInterface $instantiator,
        private readonly BlockTypeRegistry $registry,
        private readonly BlockDataReplayer $replayer,
    ) {
    }

    /**
     * @param array<string, mixed> $payload untrusted `content-blocks/section-v1` snapshot
     * @param Section|null         $after   the selected section; null appends at the end of the area
     *
     * @throws \ContentBlocks\SectionTemplate\UnsupportedTemplateFormatException when the payload envelope is not readable
     * @throws IncompatibleTemplateException                                     when no block of the payload survives
     */
    public function pasteSection(array $payload, ContentArea $area, ?Section $after): PasteResult
    {
        $result = $this->instantiator->instantiate($payload);
        $section = $result->section;

        $dropped = [];
        foreach ($section->getColumns() as $column) {
            foreach ($column->getBlocks() as $block) {
                $fields = $this->replayInto($block);
                if ($fields !== []) {
                    $dropped[] = ['blockType' => $block->getType(), 'droppedFields' => $fields];
                }
            }
        }

        $siblings = $this->alive($area->getSections()->toArray());
        $this->spliceAfter($siblings, $section, $after);
        $area->addSection($section);

        return new PasteResult($section, $result->skippedBlockCount, $result->skippedBlockTypes, $dropped);
    }

    /**
     * @param array<string, mixed> $payload untrusted `content-blocks/block-v1` snapshot
     * @param Block|null           $after   the selected block; null appends at the end of the column
     *
     * @throws UnreadableClipboardException  when the payload envelope is not readable
     * @throws IncompatibleTemplateException when the block's type is no longer registered — same
     *                                       meaning as in the section scope ("nothing survives"),
     *                                       so the caller has one condition to answer, not two
     */
    public function pasteBlock(array $payload, Column $column, ?Block $after): PasteResult
    {
        if (($payload['format'] ?? null) !== BlockSnapshotSerializerInterface::FORMAT) {
            throw new UnreadableClipboardException('payload');
        }

        $type = $payload['type'] ?? null;
        if (!is_string($type) || !$this->registry->has($type)) {
            throw new IncompatibleTemplateException(is_string($type) ? [$type] : []);
        }

        $block = new Block();
        $block->setType($type);
        $block->setDraftData(is_array($payload['data'] ?? null) ? $payload['data'] : []);
        $fields = $this->replayInto($block);

        $siblings = $this->alive($column->getBlocks()->toArray());
        $this->spliceAfter($siblings, $block, $after);
        $column->addBlock($block);

        return new PasteResult(
            $block,
            0,
            [],
            $fields === [] ? [] : [['blockType' => $type, 'droppedFields' => $fields]],
        );
    }

    /**
     * Runs the block's stored payload through its own form and writes back what
     * survived. A block whose type vanished between the instantiator's check and
     * here cannot happen (the instantiator skipped those), but the guard keeps
     * this callable on any block.
     *
     * @return list<string> the fields reset to their default
     */
    private function replayInto(Block $block): array
    {
        $type = $block->getType();
        if (!$this->registry->has($type)) {
            return [];
        }

        $result = $this->replayer->replay($type, $block->getDraftData() ?? []);
        $block->setDraftData($result->data);

        return $result->droppedFields;
    }

    /**
     * @template T of Section|Block|Column
     *
     * @param array<int, T> $items
     *
     * @return list<T>
     */
    private function alive(array $items): array
    {
        $alive = array_values(array_filter($items, static fn ($item) => !$item->isDeleted()));
        usort($alive, static fn ($a, $b) => $a->getPreviewPosition() <=> $b->getPreviewPosition());

        return $alive;
    }

    /**
     * Inserts $entity right after $after among $siblings — at the end when
     * $after is null or no longer among them — then re-indexes the lot.
     *
     * @template T of Section|Block
     *
     * @param list<T> $siblings
     * @param T       $entity
     * @param T|null  $after
     */
    private function spliceAfter(array $siblings, Section|Block $entity, Section|Block|null $after): void
    {
        $index = $after === null ? false : array_search($after, $siblings, true);
        $insertAt = $index === false ? \count($siblings) : $index + 1;
        array_splice($siblings, $insertAt, 0, [$entity]);

        foreach ($siblings as $i => $sibling) {
            $sibling->setPreviewPosition($i);
        }
    }
}
