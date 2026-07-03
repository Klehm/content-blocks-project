<?php

declare(strict_types=1);

namespace ContentBlocks\Service;

use ContentBlocks\BlockType\BlockTypeRegistry;
use ContentBlocks\Entity\Block;
use ContentBlocks\Entity\Column;
use ContentBlocks\Entity\Section;
use ContentBlocks\SectionTemplate\IncompatibleTemplateException;
use ContentBlocks\SectionTemplate\InstantiationResult;

/**
 * Rebuilds a detached draft Section from a SectionTemplate payload (as produced
 * by SectionTemplateSerializer), ready for the caller to attach to a
 * ContentArea and assign a previewPosition — mirroring SectionCloner's output.
 *
 * Two compatibility checks run against the *current* block-type registry, since
 * a snapshot may be inserted long after it was saved:
 *
 *  - Unknown block type  -> hard stop: IncompatibleTemplateException. A block
 *    with no registered type has no form/renderer, so the whole insert is
 *    refused rather than dropping content silently.
 *  - Unknown data field  -> soft warning: the block type no longer defines a
 *    key present in the stored data. The value is kept as-is (never dropped)
 *    and reported so the editor can review it. "Known" fields are the keys of
 *    the type's getDefaultData(), the only introspectable shape available.
 */
final class SectionTemplateInstantiator
{
    public function __construct(
        private readonly BlockTypeRegistry $registry,
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @throws IncompatibleTemplateException when a referenced block type is not registered
     */
    public function instantiate(array $payload): InstantiationResult
    {
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
                $unknown = $this->unknownKeys($type, $data);
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
     * Keys present in the stored data that the block type's current default
     * shape no longer declares.
     *
     * @param array<string, mixed> $data
     *
     * @return list<string>
     */
    private function unknownKeys(string $type, array $data): array
    {
        $known = array_keys($this->registry->get($type)->getDefaultData());

        return array_values(array_diff(array_keys($data), $known));
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
