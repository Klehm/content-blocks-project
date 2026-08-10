<?php

declare(strict_types=1);

namespace ContentBlocks\Tests\Fixtures;

use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Translator that hands every id straight back.
 *
 * Used where a unit under test only needs *a* translator to reach the label it
 * produces (the section-library poster, for one). Returning the id keeps the
 * assertions about the code path — "the block type's label ends up on the
 * tile" — rather than about a catalogue the package does not own.
 */
final class EchoTranslator implements TranslatorInterface
{
    public function trans(string $id, array $parameters = [], ?string $domain = null, ?string $locale = null): string
    {
        return strtr($id, $parameters);
    }

    public function getLocale(): string
    {
        return 'en';
    }
}
