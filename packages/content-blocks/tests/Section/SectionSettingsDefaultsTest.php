<?php

declare(strict_types=1);

namespace ContentBlocks\Tests\Section;

use ContentBlocks\Section\SectionSettingsDefaults;
use ContentBlocks\Section\SectionSettingsDefaultsProviderInterface;
use PHPUnit\Framework\TestCase;

final class SectionSettingsDefaultsTest extends TestCase
{
    public function testEmptyWhenNoProvidersRegistered(): void
    {
        $defaults = new SectionSettingsDefaults();

        $this->assertSame([], $defaults->get());
    }

    public function testMergesProvidersInOrderLastWins(): void
    {
        $defaults = new SectionSettingsDefaults([
            $this->provider(['widthMode' => 'full', 'maxWidth' => 1200]),
            $this->provider(['maxWidth' => 1100, 'backgroundColor' => '#ffffff']),
        ]);

        $this->assertSame(
            ['widthMode' => 'full', 'maxWidth' => 1100, 'backgroundColor' => '#ffffff'],
            $defaults->get(),
        );
    }

    public function testWithoutDefaultsStripsKeysWhereValueMatches(): void
    {
        $defaults = new SectionSettingsDefaults([
            $this->provider(['backgroundColor' => '#ffffff', 'widthMode' => 'full']),
        ]);

        $stripped = $defaults->withoutDefaults([
            'classes' => 'demo',
            'backgroundColor' => '#ffffff', // matches default → stripped
            'widthMode' => 'centered',      // diverges from default → kept
        ]);

        $this->assertSame(
            ['classes' => 'demo', 'widthMode' => 'centered'],
            $stripped,
        );
    }

    public function testWithoutDefaultsKeepsKeysAbsentFromDefaults(): void
    {
        $defaults = new SectionSettingsDefaults([
            $this->provider(['backgroundColor' => '#ffffff']),
        ]);

        $stripped = $defaults->withoutDefaults(['classes' => 'demo']);

        $this->assertSame(['classes' => 'demo'], $stripped);
    }

    public function testWithoutDefaultsHandlesEmptyInputs(): void
    {
        $defaults = new SectionSettingsDefaults([
            $this->provider(['x' => 1]),
        ]);

        $this->assertSame([], $defaults->withoutDefaults([]));
    }

    /** @param array<string, mixed> $values */
    private function provider(array $values): SectionSettingsDefaultsProviderInterface
    {
        return new class($values) implements SectionSettingsDefaultsProviderInterface {
            /** @param array<string, mixed> $values */
            public function __construct(private readonly array $values)
            {
            }

            public function getDefaults(): array
            {
                return $this->values;
            }
        };
    }
}
