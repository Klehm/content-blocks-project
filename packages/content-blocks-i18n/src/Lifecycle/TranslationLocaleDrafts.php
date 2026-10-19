<?php

declare(strict_types=1);

namespace ContentBlocks\I18n\Lifecycle;

use ContentBlocks\Entity\ContentArea;
use ContentBlocks\I18n\Locale\TranslationLocales;
use ContentBlocks\Publishing\LocaleDraft;
use ContentBlocks\Publishing\LocaleDraftsProviderInterface;

/**
 * The locales the builder's Publish and Discard dialog offers: those with a
 * pending translation, in the configured order.
 *
 * @see docs/internals/i18n.md#publishing-one-language
 */
final class TranslationLocaleDrafts implements LocaleDraftsProviderInterface
{
    public function __construct(
        private readonly TranslationDrafts $drafts,
        private readonly TranslationLocales $locales,
    ) {
    }

    public function pendingLocales(ContentArea $area): array
    {
        $pending = $this->drafts->pendingLocales($area);
        // A locale dropped from the config but still holding rows comes last.
        $order = array_flip($this->locales->getTargetLocales());
        usort($pending, static fn (string $a, string $b): int
            => [$order[$a] ?? \PHP_INT_MAX, $a] <=> [$order[$b] ?? \PHP_INT_MAX, $b]);

        return array_map(
            fn (string $code): LocaleDraft => new LocaleDraft($code, $this->locales->getLabel($code)),
            $pending,
        );
    }
}
