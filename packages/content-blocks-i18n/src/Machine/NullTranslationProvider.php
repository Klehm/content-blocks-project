<?php

declare(strict_types=1);

namespace ContentBlocks\I18n\Machine;

/**
 * The provider you get when none is configured. It fails rather than throws, so
 * an unconfigured install answers with a reason instead of a 500.
 *
 * @see docs/internals/i18n.md#machine-translation-is-a-seam
 */
final class NullTranslationProvider implements TranslationProviderInterface
{
    public const NAME = 'null';

    public static function getName(): string
    {
        return self::NAME;
    }

    public function getLabel(): string
    {
        return 'No machine translation configured';
    }

    public function supports(string $sourceLocale, string $targetLocale): bool
    {
        return false;
    }

    public function translate(array $requests, TranslationJob $job): array
    {
        return array_map(
            static fn (TranslationRequest $request): TranslationOutcome => TranslationOutcome::failure(
                $request->path,
                'no_provider_configured',
            ),
            $requests,
        );
    }
}
