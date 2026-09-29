<?php

declare(strict_types=1);

namespace ContentBlocks\Clipboard;

use ContentBlocks\BlockType\BlockTypeRegistry;
use ContentBlocks\Content\ContentManipulator;
use ContentBlocks\Content\ContentManipulatorInterface;
use ContentBlocks\Content\DraftOrder;
use ContentBlocks\Entity\Block;
use ContentBlocks\Entity\Column;
use ContentBlocks\Entity\ContentArea;
use ContentBlocks\Entity\Section;
use ContentBlocks\SectionTemplate\IncompatibleTemplateException;
use ContentBlocks\SectionTemplate\SectionTemplateInstantiatorInterface;

/**
 * Replays a clipboard payload into an area and places what comes out. Attached
 * to the parent but not flushed — persisting is the controller's job.
 *
 * @see docs/internals/clipboard.md#replay-and-placement
 */
final class ClipboardPaster
{
    private readonly ContentManipulatorInterface $content;

    public function __construct(
        private readonly SectionTemplateInstantiatorInterface $instantiator,
        private readonly BlockTypeRegistry $registry,
        private readonly BlockDataReplayer $replayer,
        ?ContentManipulatorInterface $content = null,
    ) {
        $this->content = $content ?? new ContentManipulator(null, $registry);
    }

    /**
     * @param array<string, mixed> $payload untrusted `section-v1` snapshot
     * @param Section|null         $after   selected section; null appends
     *
     * @throws \ContentBlocks\SectionTemplate\UnsupportedTemplateFormatException
     *                                       when the envelope is unreadable
     * @throws IncompatibleTemplateException when no block survives
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

        $this->content->insertSection($area, $section, DraftOrder::after(DraftOrder::sections($area), $after));

        return new PasteResult($section, $result->skippedBlockCount, $result->skippedBlockTypes, $dropped);
    }

    /**
     * @param array<string, mixed> $payload untrusted `block-v1` snapshot
     * @param Block|null           $after   selected block; null appends
     *
     * @throws UnreadableClipboardException  when the envelope is unreadable
     * @throws IncompatibleTemplateException when the type is unregistered: the
     *                                       same "nothing survives" as
     *                                       sections, so callers see one case
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

        $this->content->insertBlock($column, $block, DraftOrder::after(DraftOrder::blocks($column), $after));

        return new PasteResult(
            $block,
            0,
            [],
            $fields === [] ? [] : [['blockType' => $type, 'droppedFields' => $fields]],
        );
    }

    /**
     * Runs the stored payload through the block's own form, writing back what
     * survived. Guarded so it stays callable on any block.
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
}
