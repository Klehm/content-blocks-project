<?php

declare(strict_types=1);

namespace ContentBlocks\I18n\Tests\Locale;

use ContentBlocks\I18n\Locale\ConfiguredTargetLocalesProvider;
use ContentBlocks\I18n\Locale\TargetLocalesProviderInterface;
use ContentBlocks\I18n\Locale\TranslationLocalesFactory;
use PHPUnit\Framework\TestCase;

final class TargetLocalesProviderTest extends TestCase
{
    public function testTheConfiguredProviderReturnsTheConfiguredCodes(): void
    {
        $provider = new ConfiguredTargetLocalesProvider(['fr', 'de']);

        $this->assertSame(['fr', 'de'], $provider->getTargetLocales());
    }

    public function testTheConfiguredProviderDefaultsToNothing(): void
    {
        $this->assertSame([], (new ConfiguredTargetLocalesProvider([]))->getTargetLocales());
    }

    public function testTheFactoryDropsTheSourceAndKeepsTheProviderOrder(): void
    {
        $locales = TranslationLocalesFactory::create('en', $this->provider(['de', 'en', 'fr']));

        $this->assertSame(['de', 'fr'], $locales->getTargetLocales());
        $this->assertSame(['en', 'de', 'fr'], $locales->getAllLocales());
        $this->assertSame('en', $locales->getSourceLocale());
    }

    // A label from the config applies to a code the provider supplied.
    public function testTheFactoryAppliesTheConfiguredLabels(): void
    {
        $locales = TranslationLocalesFactory::create(
            'en',
            $this->provider(['fr_BE']),
            ['fr_BE' => 'Français (Belgique)', 'en' => 'English (source)'],
        );

        $this->assertSame('Français (Belgique)', $locales->getLabel('fr_BE'));
        $this->assertSame('English (source)', $locales->getLabel('en'));
    }

    public function testTheProviderIsAskedOnce(): void
    {
        $provider = new class () implements TargetLocalesProviderInterface {
            public int $calls = 0;

            public function getTargetLocales(): array
            {
                ++$this->calls;

                return ['fr'];
            }
        };

        $locales = TranslationLocalesFactory::create('en', $provider);
        $locales->getTargetLocales();
        $locales->isTarget('fr');
        $locales->toArray();

        $this->assertSame(1, $provider->calls);
    }

    /**
     * @param list<string> $codes
     */
    private function provider(array $codes): TargetLocalesProviderInterface
    {
        return new class ($codes) implements TargetLocalesProviderInterface {
            /** @param list<string> $codes */
            public function __construct(private readonly array $codes)
            {
            }

            public function getTargetLocales(): array
            {
                return $this->codes;
            }
        };
    }
}
