<?php

declare(strict_types=1);

namespace ContentBlocks\SectionTemplate;

use ContentBlocks\Block\BlockDataKeys;
use ContentBlocks\BlockType\BlockTypeRegistry;
use ContentBlocks\Entity\Block;
use ContentBlocks\Entity\Column;
use ContentBlocks\Entity\Section;

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
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @throws UnsupportedTemplateFormatException when the payload envelope is not readable
     * @throws IncompatibleTemplateException      when a referenced block type is not registered
     */
    public function instantiate(array $payload): InstantiationResult
    {
        $this->assertFormat($payload);

        $missing = $this->collectMissingTypes($payload);
        if ($missing !== []) {
            throw new IncompatibleTemplateException($missing);
        }

        $section = new Section();
        $section->setLayout(
            is_string($payload['layout'] ?? null) ? $payload['layout'] : Section::LAYOUT_FULL,
        );

        $settings = $payload['settings'] ?? null;
        if (is_array($settings) && $settings !== []) {
            $section->setDraftSettings($settings);
        }

        $warnings = [];
        $columns = $payload['columns'] ?? null;
        if (is_array($columns)) {
            foreach (array_values($columns) as $i => $colRaw) {
                if (!is_array($colRaw)) {
                    continue;
                }
                $column = $this->buildColumn($colRaw, $warnings);
                $column->setPreviewPosition($i);
                $section->addColumn($column);
            }
        }

        return new InstantiationResult($section, $warnings);
    }

    /**
     * @param array<string, mixed> $raw
     * @param list<array{blockType: string, unknownKeys: list<string>}> $warnings accumulator
     */
    private function buildColumn(array $raw, array &$warnings): Column
    {
        $column = new Column();
        if (isset($raw['preset']) && is_string($raw['preset'])) {
            $column->setPreset($raw['preset']);
        }

        $blocks = $raw['blocks'] ?? null;
        if (is_array($blocks)) {
            foreach (array_values($blocks) as $i => $blockRaw) {
                if (!is_array($blockRaw)) {
                    continue;
                }
                $block = $this->buildBlock($blockRaw, $warnings);
                $block->setPreviewPosition($i);
                $column->addBlock($block);
            }
        }

        return $column;
    }

    /**
     * @param array<string, mixed> $raw
     * @param list<array{blockType: string, unknownKeys: list<string>}> $warnings accumulator
     */
    private function buildBlock(array $raw, array &$warnings): Block
    {
        $block = new Block();
        $type = $raw['type'] ?? null;
        if (is_string($type)) {
            $block->setType($type);
        }

        $data = $raw['data'] ?? null;
        if (is_array($data)) {
            if (is_string($type)) {
                $unknown = $this->dataKeys->unknownIn($type, $data);
                if ($unknown !== []) {
                    $warnings[] = ['blockType' => $type, 'unknownKeys' => $unknown];
                }
            }
            // Keep the stored data verbatim, including keys the type no longer
            // defines — those only warn, they are never dropped.
            $block->setDraftData($data);
        }

        return $block;
    }

    /**
     * The envelope format is written by the serializer and versions the payload
     * *structure* (not the shape of the block data inside it, which belongs to
     * the block types). A payload written under an older structure is refused
     * rather than replayed blind.
     *
     * @param array<string, mixed> $payload
     */
    private function assertFormat(array $payload): void
    {
        $format = $payload['format'] ?? null;
        if ($format !== SectionTemplateSerializerInterface::FORMAT) {
            throw new UnsupportedTemplateFormatException(
                is_string($format) ? $format : null,
                SectionTemplateSerializerInterface::FORMAT,
            );
        }
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return list<string> distinct block-type identifiers not in the registry
     */
    private function collectMissingTypes(array $payload): array
    {
        $missing = [];
        $columns = $payload['columns'] ?? null;
        if (!is_array($columns)) {
            return [];
        }
        foreach ($columns as $colRaw) {
            if (!is_array($colRaw) || !is_array($colRaw['blocks'] ?? null)) {
                continue;
            }
            foreach ($colRaw['blocks'] as $blockRaw) {
                $type = is_array($blockRaw) ? ($blockRaw['type'] ?? null) : null;
                if (is_string($type) && !$this->registry->has($type) && !in_array($type, $missing, true)) {
                    $missing[] = $type;
                }
            }
        }

        return $missing;
    }
}
