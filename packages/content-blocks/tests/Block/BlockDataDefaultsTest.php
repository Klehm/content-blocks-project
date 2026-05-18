<?php

declare(strict_types=1);

namespace ContentBlocks\Tests\Block;

use ContentBlocks\Block\BlockDataDefaults;
use ContentBlocks\Block\BlockDataDefaultsProviderInterface;
use PHPUnit\Framework\TestCase;

final class BlockDataDefaultsTest extends TestCase
{
    public function testEmptyWhenNoProvidersRegistered(): void
    {
        $defaults = new BlockDataDefaults();

        $this->assertSame([], $defaults->get());
    }

    public function testMergesProvidersInOrderLastWins(): void
    {
        $defaults = new BlockDataDefaults([
            $this->provider(['title' => 'A', 'styling' => ['backgroundColor' => '#ffffff']]),
            $this->provider(['title' => 'B']),
        ]);

        $this->assertSame(
            ['title' => 'B', 'styling' => ['backgroundColor' => '#ffffff']],
            $defaults->get(),
        );
    }

    public function testGetRecursivelyMergesNestedDefaults(): void
    {
        $defaults = new BlockDataDefaults([
            $this->provider(['styling' => ['backgroundColor' => '#ffffff', 'maxWidth' => null]]),
            $this->provider(['styling' => ['maxWidth' => ['value' => 1200, 'unit' => 'px']]]),
        ]);

        $this->assertSame(
            [
                'styling' => [
                    'backgroundColor' => '#ffffff',
                    'maxWidth' => ['value' => 1200, 'unit' => 'px'],
                ],
            ],
            $defaults->get(),
        );
    }

    public function testWithoutDefaultsStripsKeysWhereValueMatches(): void
    {
        $defaults = new BlockDataDefaults([
            $this->provider(['styling' => ['backgroundColor' => '#ffffff']]),
        ]);

        $stripped = $defaults->withoutDefaults([
            'title' => 'Hello',
            'styling' => [
                'backgroundColor' => '#ffffff', // matches → stripped
                'maxWidth' => ['value' => 800, 'unit' => 'px'],
            ],
        ]);

        $this->assertSame(
            [
                'title' => 'Hello',
                'styling' => [
                    'maxWidth' => ['value' => 800, 'unit' => 'px'],
                ],
            ],
            $stripped,
        );
    }

    public function testWithoutDefaultsKeepsKeysAbsentFromDefaults(): void
    {
        $defaults = new BlockDataDefaults([
            $this->provider(['styling' => ['backgroundColor' => '#ffffff']]),
        ]);

        $stripped = $defaults->withoutDefaults(['title' => 'Hello']);

        $this->assertSame(['title' => 'Hello'], $stripped);
    }

    public function testWithoutDefaultsRemovesNestedArrayThatBecomesEmpty(): void
    {
        $defaults = new BlockDataDefaults([
            $this->provider(['styling' => ['backgroundColor' => '#ffffff']]),
        ]);

        $stripped = $defaults->withoutDefaults([
            'styling' => ['backgroundColor' => '#ffffff'],
        ]);

        // After stripping the only entry, the parent `styling` key is
        // pruned too — the rendered markup carries no styling at all.
        $this->assertSame([], $stripped);
    }

    public function testWithoutDefaultsHandlesEmptyInputs(): void
    {
        $defaults = new BlockDataDefaults([
            $this->provider(['styling' => ['backgroundColor' => '#ffffff']]),
        ]);

        $this->assertSame([], $defaults->withoutDefaults([]));
    }

    /** @param array<string, mixed> $values */
    private function provider(array $values): BlockDataDefaultsProviderInterface
    {
        return new class($values) implements BlockDataDefaultsProviderInterface {
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
