<?php

declare(strict_types=1);

namespace ContentBlocks\Schema;

use ContentBlocks\Block\BlockDataDefaults;
use ContentBlocks\Block\BlockDataKeys;
use ContentBlocks\BlockType\BlockCategoryRegistry;
use ContentBlocks\BlockType\BlockPurposeInterface;
use ContentBlocks\BlockType\BlockTypeInterface;
use ContentBlocks\BlockType\BlockTypeRegistry;
use ContentBlocks\Builder\BuilderStructure;
use ContentBlocks\Builder\BuilderStructureResolverInterface;
use ContentBlocks\Entity\ContentArea;
use ContentBlocks\Entity\Section;
use ContentBlocks\Form\Type\BlockFormType;
use ContentBlocks\Form\Type\SectionSettingsType;
use ContentBlocks\Palette\ColorPaletteRegistry;
use ContentBlocks\Section\ColumnSettings;
use ContentBlocks\Section\SectionLayoutRegistry;
use ContentBlocks\Section\SectionSettingsDefaults;
use ContentBlocks\Section\SectionStyleRegistry;
use ContentBlocks\SectionTemplate\SectionTemplateSerializerInterface;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Translation\TranslatableMessage;
use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Assembles the {@see ContentSchema} of an area from what the app already
 * declares: block forms, layouts, structure, palette, presets, templates.
 *
 * @see docs/internals/content-schema.md
 */
final class ContentSchemaBuilder
{
    /** Same cap as the builder's Columns panel. */
    public const MAX_COLUMNS = 20;

    /** The grid every `Column.preset` spans a part of. */
    private const GRID_UNITS = 12;

    /**
     * @param array<string, string> $schemaBlockPurposes type => purpose, wins
     *                                                   over the block's own
     */
    public function __construct(
        private readonly BlockTypeRegistry $registry,
        private readonly FormFactoryInterface $formFactory,
        private readonly FormSchemaMapper $mapper,
        private readonly BlockDataDefaults $blockDataDefaults = new BlockDataDefaults(),
        private readonly SectionSettingsDefaults $settingsDefaults = new SectionSettingsDefaults(),
        private readonly SectionLayoutRegistry $layouts = new SectionLayoutRegistry(),
        private readonly ColorPaletteRegistry $palette = new ColorPaletteRegistry(),
        private readonly SectionStyleRegistry $styles = new SectionStyleRegistry(),
        private readonly BlockCategoryRegistry $categories = new BlockCategoryRegistry(),
        private readonly ?BuilderStructureResolverInterface $structureResolver = null,
        private readonly ?SectionTemplateExamples $examples = null,
        private readonly ?TranslatorInterface $translator = null,
        private readonly bool $blockStyling = true,
        private readonly bool $sectionStyling = true,
        private readonly string $structureSections = BuilderStructure::SECTIONS_EDITABLE,
        private readonly bool $structureColumns = true,
        private readonly int $contentVersion = 1,
        private readonly array $schemaBlockPurposes = [],
    ) {
    }

    /**
     * @param ContentArea|null $area     null: the configured structure, as
     *                                   for an area the resolver has no
     *                                   special case for
     * @param int              $examples how many saved sections to include
     */
    public function build(?ContentArea $area = null, ?string $locale = null, int $examples = 3): ContentSchema
    {
        $structure = $area !== null && $this->structureResolver !== null
            ? $this->structureResolver->forArea($area)
            : new BuilderStructure($this->structureSections, $this->structureColumns);

        return new ContentSchema(
            $this->contentVersion,
            $structure->toArray(),
            $this->layoutList($locale),
            $this->paletteList(),
            $this->styleList($locale),
            $this->blockList($locale),
            $structure->showsSections() ? ($this->examples?->latest($examples) ?? []) : [],
            $this->outputSchema($structure, $locale),
        );
    }

    /**
     * The JSON Schema of one field of a block's data, by its dotted path
     * (`items.0.title`), or null when the type or the path is unknown.
     *
     * @return array<string, mixed>|null
     */
    public function fieldSchema(string $type, string $path, ?string $locale = null): ?array
    {
        if (!$this->registry->has($type) || $path === '') {
            return null;
        }

        $schema = $this->blockData($this->registry->get($type), $locale);
        foreach (explode('.', $path) as $segment) {
            if (($schema['type'] ?? null) === 'array' && ctype_digit($segment)) {
                $schema = \is_array($schema['items'] ?? null) ? $schema['items'] : null;
            } else {
                $properties = $schema['properties'] ?? null;
                $schema = \is_array($properties) && \is_array($properties[$segment] ?? null) ? $properties[$segment] : null;
            }
            if ($schema === null) {
                return null;
            }
        }

        return $schema;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function layoutList(?string $locale): array
    {
        $out = [];
        foreach ($this->layouts->all() as $layout) {
            $out[] = [
                'name' => $layout->name,
                'label' => $this->text($layout->label, 'content_blocks', $locale),
                'columns' => $layout->presets(),
                'display' => $layout->display,
            ];
        }

        return $out;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function paletteList(): array
    {
        $out = [];
        foreach ($this->palette->all() as $color) {
            $out[] = array_filter([
                'label' => $color->label,
                'color' => $color->color,
                'tone' => $this->palette->tone($color->color),
                'role' => $color->role,
            ], static fn (mixed $v): bool => $v !== null);
        }

        return $out;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function styleList(?string $locale): array
    {
        $out = [];
        foreach ($this->styles->all() as $style) {
            $out[] = array_filter([
                'name' => $style->name,
                'label' => $this->text($style->label, 'content_blocks', $locale),
                'role' => $style->role,
            ], static fn (mixed $v): bool => $v !== null);
        }

        return $out;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function blockList(?string $locale): array
    {
        $out = [];
        foreach ($this->registry->all() as $type => $blockType) {
            $out[] = array_filter([
                'type' => $type,
                'label' => $this->label($blockType->getLabel(), $locale),
                'category' => $this->categories->categoryOf($type, $this->registry->getCategory($type)),
                'purpose' => $this->purposeOf($blockType),
            ], static fn (mixed $v): bool => $v !== null);
        }

        return $out;
    }

    private function purposeOf(BlockTypeInterface $blockType): ?string
    {
        $configured = $this->schemaBlockPurposes[$blockType->getType()] ?? null;
        if (\is_string($configured) && trim($configured) !== '') {
            return trim($configured);
        }

        return $blockType instanceof BlockPurposeInterface ? $blockType->getPurpose() : null;
    }

    /**
     * A page: a list of `section-v1` payloads, the shape the paste path
     * replays. Shared subtrees live once under `$defs`.
     *
     * @return array<string, mixed>
     */
    private function outputSchema(BuilderStructure $structure, ?string $locale): array
    {
        $defs = [];
        $blockRefs = [];
        foreach ($this->registry->all() as $type => $blockType) {
            $data = $this->blockData($blockType, $locale);
            if (isset($data['properties']['styling'])) {
                $defs['blockStyling'] ??= $data['properties']['styling'];
                $data['properties']['styling'] = ['$ref' => '#/$defs/blockStyling'];
            }

            $defs['block.' . $type] = [
                'type' => 'object',
                'properties' => [
                    'type' => ['const' => $type],
                    'data' => $data,
                ],
                'required' => ['type', 'data'],
                'additionalProperties' => false,
            ];
            $blockRefs[] = ['$ref' => '#/$defs/block.' . $type];
        }

        $defs['block'] = $blockRefs === [] ? ['not' => new \stdClass()] : ['oneOf' => $blockRefs];
        $defs['column'] = $this->columnSchema();
        $defs['section'] = $this->sectionSchema($structure);
        if ($structure->showsSections()) {
            $defs['sectionSettings'] = $this->sectionSettings($structure, $locale);
        }

        $sections = ['type' => 'array', 'items' => ['$ref' => '#/$defs/section']];
        if (!$structure->showsSections()) {
            // "hidden": blocks stack in one implicit full-width section.
            $sections['maxItems'] = 1;
        }

        return [
            '$schema' => 'https://json-schema.org/draft/2020-12/schema',
            'title' => 'Content for one content area',
            'type' => 'object',
            'properties' => ['sections' => $sections],
            'required' => ['sections'],
            'additionalProperties' => false,
            '$defs' => $defs,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function sectionSchema(BuilderStructure $structure): array
    {
        $layouts = $structure->showsSections()
            ? array_map(static fn ($layout): string => $layout->name, $this->layouts->all())
            : [Section::LAYOUT_FULL];

        $properties = [
            'format' => ['const' => SectionTemplateSerializerInterface::FORMAT],
            'layout' => [
                'enum' => $layouts,
                'description' => 'One of the layouts; a new section takes its column presets.',
            ],
        ];
        if ($structure->showsSections()) {
            $properties['settings'] = ['$ref' => '#/$defs/sectionSettings'];
        }
        $properties['columns'] = [
            'type' => 'array',
            'items' => ['$ref' => '#/$defs/column'],
            'minItems' => 1,
            'maxItems' => self::MAX_COLUMNS,
        ];

        return [
            'type' => 'object',
            'properties' => $properties,
            'required' => ['format', 'layout', 'columns'],
            'additionalProperties' => false,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function columnSchema(): array
    {
        $presets = [];
        for ($span = 1; $span <= self::GRID_UNITS; ++$span) {
            $presets[] = 'col-' . $span;
        }

        return [
            'type' => 'object',
            'properties' => [
                'preset' => [
                    'enum' => $presets,
                    'description' => 'Span on a 12-unit grid; the columns of a section add up to 12.',
                ],
                'settings' => [
                    'type' => 'object',
                    'properties' => [
                        ColumnSettings::LABEL => [
                            'type' => 'string',
                            'maxLength' => ColumnSettings::LABEL_MAX_LENGTH,
                            'description' => 'Tab or accordion title, when the section shows its columns that way.',
                        ],
                    ],
                    'additionalProperties' => false,
                ],
                'blocks' => ['type' => 'array', 'items' => ['$ref' => '#/$defs/block']],
            ],
            'required' => ['preset', 'blocks'],
            'additionalProperties' => false,
        ];
    }

    /**
     * Built from the defaults, the way the paste replay builds it, so the
     * schema describes the very form that will judge the data.
     *
     * @return array<string, mixed>
     */
    private function blockData(BlockTypeInterface $blockType, ?string $locale): array
    {
        $defaults = $blockType->getDefaultData();
        $initial = array_replace_recursive($this->blockDataDefaults->get(), $defaults);
        $form = $this->formFactory->create(BlockFormType::class, $initial, [
            'block_type' => $blockType,
            'block_data' => $initial,
            'include_styling' => $this->blockStyling,
        ]);

        $schema = $this->mapper->map($form, $locale);

        // Declared without a field: the replay keeps them verbatim.
        $properties = \is_array($schema['properties']) ? $schema['properties'] : [];
        foreach ($defaults as $key => $value) {
            $key = (string) $key;
            if (isset($properties[$key]) || str_starts_with($key, BlockDataKeys::RESERVED_PREFIX)) {
                continue;
            }
            $properties[$key] = self::typeOf($value) + ['default' => $value];
        }
        $schema['properties'] = $properties === [] ? new \stdClass() : $properties;

        return $schema;
    }

    /**
     * @return array<string, mixed>
     */
    private function sectionSettings(BuilderStructure $structure, ?string $locale): array
    {
        $form = $this->formFactory->create(SectionSettingsType::class, $this->settingsDefaults->get(), [
            // Two, so the settings only a multi-column section has are listed.
            'column_count' => 2,
            'include_styling' => $this->sectionStyling,
            'include_layout' => $structure->canEditColumns(),
        ]);

        return $this->mapper->map($form, $locale);
    }

    /**
     * @return array<string, mixed>
     */
    private static function typeOf(mixed $value): array
    {
        return match (true) {
            \is_bool($value) => ['type' => 'boolean'],
            \is_int($value) => ['type' => 'integer'],
            \is_float($value) => ['type' => 'number'],
            \is_string($value) => ['type' => 'string'],
            \is_array($value) && array_is_list($value) => ['type' => 'array'],
            \is_array($value) => ['type' => 'object'],
            default => [],
        };
    }

    private function label(string|TranslatableInterface $label, ?string $locale): string
    {
        if (\is_string($label)) {
            return $label;
        }
        if ($this->translator !== null) {
            return $label->trans($this->translator, $locale);
        }

        return $label instanceof TranslatableMessage ? $label->getMessage() : '';
    }

    private function text(string $key, string $domain, ?string $locale): string
    {
        return $this->translator?->trans($key, [], $domain, $locale) ?? $key;
    }
}
