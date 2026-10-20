<?php

declare(strict_types=1);

namespace ContentBlocks\Schema;

/**
 * What a model needs to write content for one area: the vocabulary (blocks,
 * layouts, palette, presets, examples) and the JSON Schema of the output.
 *
 * @see docs/guide/content-schema.md#the-document
 */
final class ContentSchema
{
    public const FORMAT = 'content-blocks/schema-v1';

    /**
     * @param array{sections: string, columns: bool} $structure
     * @param list<array<string, mixed>>             $layouts
     * @param list<array<string, mixed>>             $palette
     * @param list<array<string, mixed>>             $sectionStyles
     * @param list<array<string, mixed>>             $blocks
     * @param list<array<string, mixed>>             $examples
     * @param array<string, mixed>                   $schema
     */
    public function __construct(
        public readonly int $contentVersion,
        public readonly array $structure,
        public readonly array $layouts,
        public readonly array $palette,
        public readonly array $sectionStyles,
        public readonly array $blocks,
        public readonly array $examples,
        public readonly array $schema,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'format' => self::FORMAT,
            'contentVersion' => $this->contentVersion,
            'structure' => $this->structure,
            'layouts' => $this->layouts,
            'palette' => $this->palette,
            'sectionStyles' => $this->sectionStyles,
            'blocks' => $this->blocks,
            'examples' => $this->examples,
            'schema' => $this->schema,
        ];
    }
}
