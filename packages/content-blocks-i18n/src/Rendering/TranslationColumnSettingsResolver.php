<?php

declare(strict_types=1);

namespace ContentBlocks\I18n\Rendering;

use ContentBlocks\Entity\Column;
use ContentBlocks\I18n\Entity\ColumnTranslation;
use ContentBlocks\I18n\Locale\RenderLocaleResolverInterface;
use ContentBlocks\I18n\Storage\TranslationStore;
use ContentBlocks\Rendering\ColumnSettingsResolverInterface;
use ContentBlocks\Rendering\RenderContext;
use ContentBlocks\Rendering\RenderMode;
use ContentBlocks\Section\ColumnSettings;

/**
 * A column's tab title in the render locale. A blank translation falls back
 * to the source, like a missing one: an empty tab cannot be clicked.
 *
 * @see docs/internals/i18n.md#tab-titles-are-translated-beside-the-column
 */
final class TranslationColumnSettingsResolver implements ColumnSettingsResolverInterface
{
    public function __construct(
        private readonly TranslationStore $store,
        private readonly RenderLocaleResolverInterface $localeResolver,
    ) {
    }

    public function resolve(Column $column, RenderContext $context, array $settings): array
    {
        $locale = $this->localeResolver->resolve($context);

        // Nothing to translate from: a label only renders where one is set.
        if ($locale === null || ColumnSettings::label($settings) === null) {
            return $settings;
        }

        $values = $this->store->columnPayloadFor($column, $locale, $context->mode ?? RenderMode::PUBLIC)['values'];
        $label = $values[ColumnTranslation::LABEL] ?? null;

        if (\is_string($label) && trim($label) !== '') {
            $settings[ColumnSettings::LABEL] = $label;
        }

        return $settings;
    }
}
