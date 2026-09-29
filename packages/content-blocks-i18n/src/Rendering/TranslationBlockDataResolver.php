<?php

declare(strict_types=1);

namespace ContentBlocks\I18n\Rendering;

use ContentBlocks\Entity\Block;
use ContentBlocks\I18n\Field\FieldPath;
use ContentBlocks\I18n\Locale\RenderLocaleResolverInterface;
use ContentBlocks\I18n\Locale\TranslationLocales;
use ContentBlocks\I18n\Storage\TranslationStore;
use ContentBlocks\Rendering\BlockDataResolverInterface;
use ContentBlocks\Rendering\RenderContext;
use ContentBlocks\Rendering\RenderMode;
use ContentBlocks\Translation\TranslatableFieldsInterface;

/**
 * The package's render-time footprint: the locale's values, then its
 * fallbacks', merged over the source payload per field, at priority 128.
 *
 * @see docs/internals/i18n.md#the-allow-list-runs-again-at-render
 * @see docs/internals/i18n.md#the-fallback-chain
 */
final class TranslationBlockDataResolver implements BlockDataResolverInterface
{
    public const PRIORITY = 128;

    public function __construct(
        private readonly TranslationStore $store,
        private readonly RenderLocaleResolverInterface $localeResolver,
        private readonly TranslatableFieldsInterface $translatableFields,
        private readonly ?TranslationLocales $locales = null,
    ) {
    }

    public function resolve(Block $block, RenderContext $context, array $data): array
    {
        $locale = $this->localeResolver->resolve($context);

        if ($locale === null || $data === []) {
            return $data;
        }

        $mode = $context->mode ?? RenderMode::PUBLIC;
        $values = [];

        // Per field, the first locale of the chain with a value wins.
        foreach ([$locale, ...$this->locales?->getFallbacks($locale) ?? []] as $candidate) {
            $values += $this->store->payloadFor($block, $candidate, $mode)['values'];
        }

        if ($values === []) {
            return $data;
        }

        $allowed = $this->translatableFields->forBlockType($block->getType(), $data);

        foreach ($values as $path => $value) {
            // A value is text: an array or a number would reshape the data.
            if (!\is_string($path) || !\is_string($value) || !FieldPath::matchesAny($path, $allowed)) {
                continue;
            }

            // write() only touches existing structure, so a row left from a
            // deleted entry is a no-op rather than a resurrection.
            $data = FieldPath::write($data, $path, $value);
        }

        return $data;
    }
}
