<?php

declare(strict_types=1);

namespace ContentBlocks\I18n\Machine;

use Symfony\Contracts\Translation\TranslatableInterface;

/**
 * A machine-translation backend — DeepL, an LLM, Google, an in-house service, a
 * human-translation vendor's API.
 *
 * Tag with `content_blocks_i18n.translation_provider`, or just implement the
 * interface: it is autoconfigured. Registering one is the *whole* integration —
 * the workbench's per-field button and its "translate the page" button both go
 * through {@see MachineTranslator}, which goes through here.
 *
 * ---- Why the contract is a batch ----
 *
 * `translate()` takes a list, not a string, and that is the single most
 * important decision in this interface. Translating a page means 50–200 short
 * strings; done one HTTP call at a time it is slow enough that editors stop
 * using it, and on metered APIs it multiplies the per-request overhead by 200.
 * A per-field click simply passes a list of one, so there is no second code path
 * to maintain and no way for the two to drift.
 *
 * ---- Contract ----
 *
 *  - **Return one outcome per request, matched by `path`.** Order is not
 *    trusted; the caller reads `path`. A request you cannot handle gets a
 *    failure outcome, not a missing entry.
 *  - **Throw only for whole-batch failures** — bad credentials, unreachable
 *    host. Per-string trouble is a {@see TranslationOutcome::failure()}, so the
 *    rest of the page still gets translated.
 *  - **Respect `format`.** A request marked HTML holds markup; returning
 *    escaped or tag-stripped text corrupts the block.
 *  - **Never write anything.** Persistence, the allow-list and the digests are
 *    {@see \ContentBlocks\I18n\Storage\TranslationWriter}'s job; a provider that
 *    also stored results would bypass every check.
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
