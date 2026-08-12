<?php

declare(strict_types=1);

namespace App\Translation;

use ContentBlocks\I18n\Machine\TranslationJob;
use ContentBlocks\I18n\Machine\TranslationOutcome;
use ContentBlocks\I18n\Machine\TranslationProviderInterface;
use ContentBlocks\I18n\Machine\TranslationRequest;
use Symfony\Contracts\Translation\TranslatableInterface;

/**
 * A translation provider that translates nothing: it tags each string with its
 * target locale and hands it straight back.
 *
 * ---- Why the sandbox ships one ----
 *
 * The machine-translation flow is the part of this feature most worth having an
 * end-to-end test for — the round trip from a click, through batching, through
 * the write gate, to a changed page — and none of that has anything to do with
 * the quality of the translation. Wiring a real engine into that test would buy
 * nothing and cost a network call, an API key in CI, and a suite that fails when
 * someone else's rate limit does.
 *
 * So this is the sandbox's default provider: deterministic, instant, offline.
 * `ClaudeTranslationProvider` sits next to it for anyone who wants to see real
 * output, and takes over as soon as `ANTHROPIC_API_KEY` is set.
 *
 * The output is deliberately obvious — `[EN] Bienvenue` — because a pseudo
 * translation that looked plausible would be the worst of both worlds: nobody
 * could tell at a glance whether a page was really translated.
 */
final class PseudoTranslationProvider implements TranslationProviderInterface
{
    public const NAME = 'pseudo';

    public static function getName(): string
    {
        return self::NAME;
    }

    public function getLabel(): string|TranslatableInterface
    {
        return 'Pseudo (demo, no network)';
    }

    public function supports(string $sourceLocale, string $targetLocale): bool
    {
        return true;
    }

    public function translate(array $requests, TranslationJob $job): array
    {
        $tag = '[' . strtoupper($job->targetLocale) . '] ';

        return array_map(
            static function (TranslationRequest $request) use ($tag): TranslationOutcome {
                // Prefixing inside the markup rather than in front of it keeps
                // an HTML payload valid — the same care a real provider has to
                // take, and worth exercising in the e2e path.
                $text = $request->isHtml()
                    ? preg_replace('/(>)([^<>]*\S[^<>]*)(<)/u', '$1' . $tag . '$2$3', $request->text, 1) ?? $request->text
                    : $tag . $request->text;

                return TranslationOutcome::success($request->path, $text);
            },
            $requests,
        );
    }
}
