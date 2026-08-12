<?php

declare(strict_types=1);

namespace ContentBlocks\I18n\Rendering;

use ContentBlocks\Entity\Block;
use ContentBlocks\I18n\Field\FieldPath;
use ContentBlocks\I18n\Locale\RenderLocaleResolverInterface;
use ContentBlocks\I18n\Storage\TranslationStore;
use ContentBlocks\Rendering\BlockDataResolverInterface;
use ContentBlocks\Rendering\RenderContext;
use ContentBlocks\Rendering\RenderMode;
use ContentBlocks\Translation\TranslatableFieldsInterface;

/**
 * Merges the target locale's field values over the block's source payload.
 *
 * This is the entire render-time footprint of the package: one resolver in the
 * core's pipeline, registered by autoconfiguration. With no locale resolved it
 * returns `$data` untouched, so an installation that has not translated
 * anything renders byte-for-byte what it did before the package was installed.
 *
 * ---- Fallback is per field, not per block ----
 *
 * A field with no stored value keeps its source text. The alternative — falling
 * back to the source for the whole block as soon as one field is untranslated —
 * makes a half-translated page look broken rather than incomplete, and it makes
 * incremental translation pointless, since nothing shows until everything is
 * done.
 *
 * ---- The allow-list runs again here ----
 *
 * {@see \ContentBlocks\I18n\Storage\TranslationWriter} already refuses to store
 * a value for an untagged field, so re-checking looks redundant. It is not: tags
 * are code and rows are data, and code changes. A field that was translatable
 * last release and is not any more has rows sitting in the table; without this
 * check they would keep overriding a field the block no longer considers
 * translatable. The tags are the current truth, so the tags win.
 *
 * ---- Priority ----
 *
 * 128: below the core's seeding resolver (256), above the default 0. Translation
 * is closer to "what the payload *is*" than to a transformation of it, so a host
 * resolver that substitutes tokens or injects computed values at the default
 * priority sees text already in the right language.
 */
final class TranslationBlockDataResolver implements BlockDataResolverInterface
{
    public const PRIORITY = 128;

    public function __construct(
        private readonly TranslationStore $store,
        private readonly RenderLocaleResolverInterface $localeResolver,
        private readonly TranslatableFieldsInterface $translatableFields,
    ) {
    }

    public function resolve(Block $block, RenderContext $context, array $data): array
    {
        $locale = $this->localeResolver->resolve($context);

        if ($locale === null || $data === []) {
            return $data;
        }

        $payload = $this->store->payloadFor($block, $locale, $context->mode ?? RenderMode::PUBLIC);

        if ($payload['values'] === []) {
            return $data;
        }

        $allowed = $this->translatableFields->forBlockType($block->getType(), $data);

        foreach ($payload['values'] as $path => $value) {
            if (!\is_string($path) || !FieldPath::matchesAny($path, $allowed)) {
                continue;
            }

            // FieldPath::write only touches structure that already exists, so a
            // row left over from a deleted collection entry is a no-op rather
            // than a resurrection.
            $data = FieldPath::write($data, $path, $value);
        }

        return $data;
    }
}
