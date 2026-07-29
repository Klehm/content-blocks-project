<?php

declare(strict_types=1);

namespace ContentBlocks\SectionTemplate;

use ContentBlocks\BlockType\BlockTypeRegistry;
use ContentBlocks\Entity\Block;
use ContentBlocks\Entity\Column;
use ContentBlocks\Entity\Section;
use ContentBlocks\Form\Type\BlockFormType;
use Symfony\Component\Form\FormFactoryInterface;

/**
 * Default {@see SectionTemplateInstantiatorInterface} — see it for the contract.
 *
 * "Known" keys are the union of two sources, because neither alone describes
 * what a block legitimately stores:
 *
 *  - the type's getDefaultData() — covers keys a block declares but does not
 *    expose as a form field;
 *  - the children of the block's built edit form — covers `styling` (added by
 *    BlockFormType, deliberately absent from getDefaultData()) and every field
 *    contributed by a host {@see \ContentBlocks\Form\Extension\BlockFormExtensionInterface}.
 *
 * Using getDefaultData() alone made the insert warn about `styling` on any
 * styled block, and about every host-added field.
 */
final class SectionTemplateInstantiator implements SectionTemplateInstantiatorInterface
{
    public function __construct(
        private readonly BlockTypeRegistry $registry,
        private readonly FormFactoryInterface $formFactory,
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
     * Keys present in the stored data that nothing in the block's current
     * shape can hold — see the class docblock for what counts as "known".
     *
     * @param array<string, mixed> $data
     *
     * @return list<string>
     */
    private function unknownKeys(string $type, array $data): array
    {
        $blockType = $this->registry->get($type);

        // Building the form (rather than reading a static declaration) is the
        // only definition that stays true when a host adds a field through a
        // BlockFormExtension. Only the builder is created — no view, no data
        // mapping — so this stays cheap enough for an admin-side insert.
        $builder = $this->formFactory->createBuilder(BlockFormType::class, null, [
            'block_type' => $blockType,
            'block_data' => $data,
        ]);

        $known = [
            ...array_keys($blockType->getDefaultData()),
            ...array_keys($builder->all()),
        ];

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
