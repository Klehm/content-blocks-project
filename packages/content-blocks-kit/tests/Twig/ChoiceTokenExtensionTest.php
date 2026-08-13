<?php

declare(strict_types=1);

namespace ContentBlocks\Kit\Tests\Twig;

use ContentBlocks\Kit\Twig\ChoiceTokenExtension;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The guard that replaced the kit views' inline value whitelists.
 *
 * Those lists conflated two jobs: deciding which values *exist* (now the
 * block's business, and configurable) and deciding which values are *safe to
 * interpolate into a class attribute* (still the view's business, and not
 * negotiable). This keeps only the second.
 */
final class ChoiceTokenExtensionTest extends TestCase
{
    /**
     * @return iterable<string, array{mixed, string}>
     */
    public static function passesThroughProvider(): iterable
    {
        yield 'a coded value' => ['primary', 'primary'];
        yield 'a value the kit never coded' => ['ghost', 'ghost'];
        yield 'hyphens' => ['brand-outline', 'brand-outline'];
        yield 'underscores' => ['brand_outline', 'brand_outline'];
        yield 'digits' => ['h1', 'h1'];
        yield 'an integer, as YAML may hand it over' => [2, '2'];
    }

    #[DataProvider('passesThroughProvider')]
    public function testWellFormedValuesPassThrough(mixed $value, string $expected): void
    {
        $this->assertSame($expected, (new ChoiceTokenExtension())->token($value, 'fallback'));
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function fallsBackProvider(): iterable
    {
        yield 'a space would become a second class' => ['ghost primary'];
        yield 'a quote would break out of the attribute' => ['ghost"'];
        yield 'an angle bracket' => ['<b>'];
        yield 'empty leaves a dangling suffix' => [''];
        yield 'null' => [null];
        yield 'an array' => [['ghost']];
        yield 'an object' => [new \stdClass()];
        yield 'over 64 characters' => [str_repeat('a', 65)];
    }

    #[DataProvider('fallsBackProvider')]
    public function testMalformedValuesFallBack(mixed $value): void
    {
        $this->assertSame('fallback', (new ChoiceTokenExtension())->token($value, 'fallback'));
    }

    public function testTheFunctionIsExposedToTwig(): void
    {
        $names = array_map(
            static fn ($f): string => $f->getName(),
            (new ChoiceTokenExtension())->getFunctions(),
        );

        $this->assertSame(['cb_kit_token'], $names);
    }
}
