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
 * The package's entire render-time footprint: one resolver merging the locale's
 * values over the source payload, per field, at priority 128.
 *
 * @see docs/internals/i18n.md#the-allow-list-runs-again-at-render
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

            // write() only touches existing structure, so a row left from a
            // deleted entry is a no-op rather than a resurrection.
            $data = FieldPath::write($data, $path, $value);
        }

        return $data;
    }
}
