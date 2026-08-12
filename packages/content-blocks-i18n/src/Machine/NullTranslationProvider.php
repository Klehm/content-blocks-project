<?php

declare(strict_types=1);

namespace ContentBlocks\I18n\Machine;

use Symfony\Contracts\Translation\TranslatableInterface;

/**
 * The provider you get when none is configured: every request comes back as a
 * failure carrying `no_provider_configured`.
 *
 * It fails rather than throws on purpose. The alternative — no provider service
 * at all, and a controller that 500s — makes an unconfigured installation look
 * like a broken one, and gives the editor nothing to act on. Here the machine
 * translation button is present, the click is answered, and the answer says
 * exactly what is missing.
 *
 * Same "inert but honest" default the rest of the suite uses: `NullFileStorage`
 * for uploads, `DenyAllAccessChecker` for authorization.
 */
final class NullTranslationProvider implements TranslationProviderInterface
{
    public const NAME = 'null';

    public static function getName(): string
    {
        return self::NAME;
    }

    public function getLabel(): string|TranslatableInterface
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
