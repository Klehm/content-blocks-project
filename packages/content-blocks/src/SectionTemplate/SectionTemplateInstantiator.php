<?php

declare(strict_types=1);

namespace ContentBlocks\SectionTemplate;

use ContentBlocks\Block\BlockDataKeys;
use ContentBlocks\Block\BlockRestoreTally;
use ContentBlocks\Block\CollectionIdBackfiller;
use ContentBlocks\BlockType\BlockTypeRegistry;
use ContentBlocks\Entity\Block;
use ContentBlocks\Entity\Column;
use ContentBlocks\Entity\Section;
use ContentBlocks\Section\ColumnSettings;
use ContentBlocks\Section\RestoredStructure;
use ContentBlocks\Section\SectionLayoutRegistry;
use ContentBlocks\Versioning\EnvelopeUpgradeChain;

/**
 * Default {@see SectionTemplateInstantiatorInterface} — see it for the
 * contract. Known data keys are decided by {@see BlockDataKeys}.
 */
final class SectionTemplateInstantiator implements SectionTemplateInstantiatorInterface
{
    public function __construct(
        private readonly BlockTypeRegistry $registry,
        private readonly BlockDataKeys $dataKeys,
        private readonly EnvelopeUpgradeChain $envelopes = new EnvelopeUpgradeChain(),
        private readonly ?CollectionIdBackfiller $collectionIds = null,
        private readonly SectionLayoutRegistry $layouts = new SectionLayoutRegistry(),
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @throws UnsupportedTemplateFormatException on an unreadable envelope
     * @throws IncompatibleTemplateException      when no block survives
     */
    public function instantiate(array $payload): InstantiationResult
    {
        $payload = $this->normalizeEnvelope($payload);

        $section = new Section();
        $section->setLayout(
            RestoredStructure::layout($payload['layout'] ?? null, $this->layouts) ?? Section::LAYOUT_FULL,
        );

        $settings = $payload['settings'] ?? null;
        if (is_array($settings) && $settings !== []) {
            $section->setDraftSettings($settings);
        }

        $tally = new BlockRestoreTally();
        $columns = $payload['columns'] ?? null;
        if (is_array($columns)) {
            foreach (array_values($columns) as $i => $colRaw) {
                if (!is_array($colRaw)) {
                    continue;
                }
                $column = $this->buildColumn($colRaw, $tally);
                $column->setPreviewPosition($i);
                $section->addColumn($column);
            }
        }

        // Had blocks, kept none. One that never had any inserts fine.
        if ($tally->keptCount() === 0 && $tally->skippedCount() > 0) {
            throw new IncompatibleTemplateException($tally->skippedTypes());
        }

        return new InstantiationResult(
            $section,
            $tally->skippedCount(),
            $tally->skippedTypes(),
            $tally->unknownFields(),
        );
    }

    /**
     * @param array<string, mixed> $raw
     */
    private function buildColumn(array $raw, BlockRestoreTally $tally): Column
    {
        $column = new Column();
        $preset = RestoredStructure::preset($raw['preset'] ?? null);
        if ($preset !== null) {
            $column->setPreset($preset);
        }
        $settings = ColumnSettings::sanitize($raw['settings'] ?? null);
        if ($settings !== []) {
            $column->setDraftSettings($settings);
        }

        $blocks = $raw['blocks'] ?? null;
        if (is_array($blocks)) {
            // Positions are assigned from the *kept* blocks so a skipped one
            // doesn't leave a hole in the sequence.
            $position = 0;
            foreach (array_values($blocks) as $blockRaw) {
                if (!is_array($blockRaw)) {
                    continue;
                }
                $block = $this->buildBlock($blockRaw, $tally);
                if ($block === null) {
                    continue;
                }
                $block->setPreviewPosition($position++);
                $column->addBlock($block);
            }
        }

        return $column;
    }

    /**
     * @param array<string, mixed> $raw
     *
     * @return Block|null null when the block's type is no longer registered
     */
    private function buildBlock(array $raw, BlockRestoreTally $tally): ?Block
    {
        $type = $raw['type'] ?? null;
        if (!is_string($type)) {
            return null;
        }
        if (!$this->registry->has($type)) {
            $tally->skip($type);

            return null;
        }

        $block = new Block();
        $block->setType($type);

        $data = $raw['data'] ?? null;
        if (is_array($data)) {
            $tally->noteUnknownKeys($type, $this->dataKeys->unknownIn($type, $data));
            // Kept verbatim, including keys the type no longer declares — those
            // warn, they are never dropped. Missing entry ids are added.
            $block->setDraftData($this->collectionIds !== null
                ? $this->collectionIds->backfill($type, $data)
                : $data);
        }

        $tally->keep();

        return $block;
    }

    /**
     * Migrated forward by {@see EnvelopeUpgradeChain} when a step exists for
     * the structure, and refused otherwise.
     *
     * @see docs/internals/section-templates.md#versioning-the-envelope
     *
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    private function normalizeEnvelope(array $payload): array
    {
        $target = SectionTemplateSerializerInterface::FORMAT;
        $format = $payload['format'] ?? null;

        if (!is_string($format) || !$this->envelopes->supports($format, $target)) {
            throw new UnsupportedTemplateFormatException(is_string($format) ? $format : null, $target, );
        }

        return $this->envelopes->upgrade($payload, $format, $target);
    }
}
