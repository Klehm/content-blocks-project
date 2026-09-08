<?php

declare(strict_types=1);

namespace ContentBlocks\I18n\Machine;

use Symfony\Contracts\Translation\TranslatableInterface;

/**
 * A machine-translation backend. Autoconfigured, batch-shaped, and shipped
 * without an adapter for any engine — that choice belongs to the host.
 *
 * @see docs/internals/i18n.md#machine-translation-is-a-seam
 *
 * Contract: one outcome per request matched by `path` (order is not trusted);
 * throw only for whole-batch failures; respect `format`; never write anything.
 */
interface TranslationProviderInterface
{
    /**
     * Stable id used in config (`content_blocks_i18n.machine.default`), on the
     * command line and in the API — so it is a slug, not a display name.
     */
    public static function getName(): string;

    /** Shown in the provider picker. */
    public function getLabel(): string|TranslatableInterface;

    /**
     * Whether this pair is supported. A provider that cannot answer cheaply
     * should return true and fail per-request rather than block the attempt.
     */
    public function supports(string $sourceLocale, string $targetLocale): bool;

    /**
     * @param list<TranslationRequest> $requests
     *
     * @return list<TranslationOutcome> one per request, matched by path
     */
    public function translate(array $requests, TranslationJob $job): array;
}
