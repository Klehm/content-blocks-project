<?php

declare(strict_types=1);

namespace ContentBlocks\SectionTemplate;

use ContentBlocks\Block\BlockDataKeys;
use ContentBlocks\Block\BlockRestoreTally;
use ContentBlocks\BlockType\BlockTypeRegistry;
use ContentBlocks\Entity\Block;
use ContentBlocks\Entity\Column;
use ContentBlocks\Entity\Section;
use ContentBlocks\Versioning\EnvelopeUpgradeChain;

/**
 * Default {@see SectionTemplateInstantiatorInterface} — see it for the contract.
 * What counts as a "known" data key is decided by {@see BlockDataKeys}, shared
 * with the import flow.
 */
final class SectionTemplateInstantiator implements SectionTemplateInstantiatorInterface
{
    public function __construct(
        private readonly BlockTypeRegistry $registry,
        private readonly BlockDataKeys $dataKeys,
        private readonly EnvelopeUpgradeChain $envelopes = new EnvelopeUpgradeChain(),
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @throws UnsupportedTemplateFormatException when the payload envelope is not readable
     * @throws IncompatibleTemplateException      when no block of the template survives
     */
    public function instantiate(array $payload): InstantiationResult
    {
        $payload = $this->normalizeEnvelope($payload);

        $section = new Section();
        $section->setLayout(
            is_string($payload['layout'] ?? null) ? $payload['layout'] : Section::LAYOUT_FULL,
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

        // A template that had blocks but kept none has nothing left to insert;
        // dropping an empty section into the area would only puzzle the editor.
        // A template that never had any (a spacer section, say) inserts fine.
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
        if (isset($raw['preset']) && is_string($raw['preset'])) {
            $column->setPreset($raw['preset']);
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
        if (is_string($type) && !$this->registry->has($type)) {
            $tally->skip($type);

            return null;
        }

        $block = new Block();
        if (is_string($type)) {
            $block->setType($type);
        }

        $data = $raw['data'] ?? null;
        if (is_array($data)) {
            if (is_string($type)) {
                $tally->noteUnknownKeys($type, $this->dataKeys->unknownIn($type, $data));
            }
            // Kept verbatim, including keys the type no longer declares — those
            // warn, they are never dropped.
            $block->setDraftData($data);
        }

        $tally->keep();

        return $block;
    }

    /**
     * The envelope format versions the payload *structure* — which this package
     * owns, unlike the block data inside it. An older structure is migrated
     * forward by {@see EnvelopeUpgradeChain} when a step exists for it, and
     * refused otherwise: replaying a structure we cannot read would quietly
     * produce half-empty sections.
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
            throw new UnsupportedTemplateFormatException(
                is_string($format) ? $format : null,
                $target,
            );
        }

        return $this->envelopes->upgrade($payload, $format, $target);
    }
}
