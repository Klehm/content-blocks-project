<?php

declare(strict_types=1);

namespace ContentBlocks\I18n\Tests\Locale;

use ContentBlocks\I18n\Locale\ConfiguredLocaleFallbacksProvider;
use ContentBlocks\I18n\Locale\ConfiguredTargetLocalesProvider;
use ContentBlocks\I18n\Locale\LocaleFallbacksProviderInterface;
use ContentBlocks\I18n\Locale\TranslationLocalesFactory;
use PHPUnit\Framework\TestCase;

final class LocaleFallbacksProviderTest extends TestCase
{
    public function testTheConfiguredProviderReturnsTheConfiguredChains(): void
    {
        $provider = new ConfiguredLocaleFallbacksProvider(['fr_CA' => ['fr']]);

        $this->assertSame(['fr_CA' => ['fr']], $provider->getFallbacks());
    }

    public function testWithoutAProviderNoLocaleHasFallbacks(): void
    {
        $locales = TranslationLocalesFactory::create('en', new ConfiguredTargetLocalesProvider(['fr', 'fr_CA']));

        $this->assertSame([], $locales->getFallbacks('fr_CA'));
    }

    public function testTheFactoryPassesTheProviderChains(): void
    {
        $locales = TranslationLocalesFactory::create(
            'en',
            new ConfiguredTargetLocalesProvider(['fr', 'fr_CA', 'es']),
            [],
            $this->provider(['fr_CA' => ['fr', 'es']]),
        );

        $this->assertSame(['fr', 'es'], $locales->getFallbacks('fr_CA'));
    }

    // The boot-time check covers the config only; a provider is filtered.
    public function testAHostProviderIsFilteredAtRuntime(): void
    {
        $locales = TranslationLocalesFactory::create(
            'en',
            new ConfiguredTargetLocalesProvider(['fr', 'fr_CA', 'de_AT', 'de']),
            [],
            $this->provider([
                'fr_CA' => ['en', 'fr_CA', 'it', 'fr'],
                'de_AT' => 'de',
                'en' => ['fr'],
            ]),
        );

        $this->assertSame(['fr'], $locales->getFallbacks('fr_CA'));
        $this->assertSame(['de'], $locales->getFallbacks('de_AT'));
    }

    /** @param array<string, mixed> $chains */
    private function provider(array $chains): LocaleFallbacksProviderInterface
    {
        return new class ($chains) implements LocaleFallbacksProviderInterface {
            /** @param array<string, mixed> $chains */
            public function __construct(private readonly array $chains)
            {
            }

            public function getFallbacks(): array
            {
                /** @var array<string, list<string>> */
                return $this->chains;
            }
        };
    }
}
