<?php

declare(strict_types=1);

namespace ContentBlocks\Schema;

use ContentBlocks\Form\Type\PaletteColorType;
use ContentBlocks\Palette\ColorPaletteRegistry;
use Symfony\Component\Form\FormInterface;

/**
 * A {@see PaletteColorType} stores one string, not its two children: none,
 * a palette entry as declared, or a free hex when the field allows one.
 */
final class PaletteColorFieldSchema implements FieldSchemaContributorInterface
{
    public function __construct(
        private readonly ?ColorPaletteRegistry $palette = null,
    ) {
    }

    public function schemaFor(FormInterface $field): ?array
    {
        if (!FormSchemaMapper::is($field, PaletteColorType::class)) {
            return null;
        }

        $named = ['type' => 'string', 'enum' => ['', ...$this->colors()]];
        if (!$field->getConfig()->getOption('allow_custom')) {
            return $named;
        }

        return [
            'description' => 'Empty for none. Prefer a palette color; a free #rrggbb leaves the brand palette.',
            'anyOf' => [$named, ['type' => 'string', 'pattern' => '^#[0-9a-fA-F]{6}$']],
        ];
    }

    /**
     * @return list<string>
     */
    private function colors(): array
    {
        $colors = [];
        foreach ($this->palette?->all() ?? [] as $entry) {
            $colors[] = $entry->color;
        }

        return array_values(array_unique($colors));
    }
}
