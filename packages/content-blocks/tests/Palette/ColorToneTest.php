<?php

declare(strict_types=1);

namespace ContentBlocks\Tests\Palette;

use ContentBlocks\Palette\ColorTone;
use ContentBlocks\Twig\ColorToneExtension;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

final class ColorToneTest extends TestCase
{
    /** @return iterable<string, array{mixed, ?string}> */
    public static function colors(): iterable
    {
        yield 'black' => ['#000000', ColorTone::DARK];
        yield 'white' => ['#ffffff', ColorTone::LIGHT];
        yield 'short form' => ['#FFF', ColorTone::LIGHT];
        yield 'brand raspberry' => ['#eb0540', ColorTone::DARK];
        yield 'pale slate' => ['#f1f5f9', ColorTone::LIGHT];
        yield 'yellow reads light' => ['#facc15', ColorTone::LIGHT];
        yield 'empty' => ['', null];
        yield 'named colour' => ['red', null];
        yield 'rgb()' => ['rgb(0, 0, 0)', null];
        yield 'not a string' => [12, null];
    }

    #[DataProvider('colors')]
    public function testToneOf(mixed $color, ?string $tone): void
    {
        $this->assertSame($tone, ColorTone::of($color));
        $this->assertSame($tone === ColorTone::DARK, ColorTone::isDark($color));
        $this->assertSame($tone === ColorTone::LIGHT, ColorTone::isLight($color));
    }

    public function testLuminanceSpansZeroToOne(): void
    {
        $this->assertSame(0.0, ColorTone::luminance('#000'));
        $this->assertEqualsWithDelta(1.0, ColorTone::luminance('#fff'), 0.0001);
        $this->assertNull(ColorTone::luminance('#12345'));
    }

    public function testTwigFunctions(): void
    {
        $twig = new Environment(new ArrayLoader([
            't' => '{{ cb_color_tone(bg) ?? "none" }}|{{ cb_color_is_dark(bg) ? "y" : "n" }}',
        ]));
        $twig->addExtension(new ColorToneExtension());

        $this->assertSame('dark|y', $twig->render('t', ['bg' => '#111827']));
        $this->assertSame('none|n', $twig->render('t', ['bg' => '']));
    }
}
