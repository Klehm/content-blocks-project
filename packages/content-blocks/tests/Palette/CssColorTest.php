<?php

declare(strict_types=1);

namespace ContentBlocks\Tests\Palette;

use ContentBlocks\Palette\CssColor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CssColorTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function colours(): iterable
    {
        yield 'short hex' => ['#abc'];
        yield 'hex' => ['#eb0540'];
        yield 'hex with alpha' => ['#eb054080'];
        yield 'name' => ['rebeccapurple'];
        yield 'rgb' => ['rgb(235, 5, 64)'];
        yield 'rgba percent' => ['rgba(10%, 20%, 30%, .5)'];
        yield 'hsl' => ['hsl(210 50% 40%)'];
        yield 'host variable' => ['var(--brand-primary)'];
    }

    /** @return iterable<string, array{mixed}> */
    public static function notColours(): iterable
    {
        yield 'second declaration' => ['red;position:fixed'];
        yield 'url' => ['url(https://evil/x)'];
        yield 'image-set' => ['image-set(x 1x)'];
        yield 'expression' => ['expression(alert(1))'];
        yield 'quote' => ['red"'];
        yield 'closing brace' => ['red}body{display:none'];
        yield 'escape' => ['\\72 ed'];
        yield 'empty' => [''];
        yield 'not a string' => [['#fff']];
    }

    #[DataProvider('colours')]
    public function testAColourValueIsKept(string $colour): void
    {
        $this->assertSame($colour, CssColor::safe($colour));
    }

    #[DataProvider('notColours')]
    public function testAnythingElseIsRefused(mixed $value): void
    {
        $this->assertNull(CssColor::safe($value));
    }

    /** @return iterable<string, array{mixed, ?string}> */
    public static function swatches(): iterable
    {
        yield 'hex' => ['#eb0540', '#eb0540'];
        yield 'short hex' => ['#FFF', '#FFF'];
        yield 'variable with hex fallback' => ['var(--theme-primary, #eb0540)', '#eb0540'];
        yield 'no space before fallback' => ['var(--x,#fff)', '#fff'];
        yield 'nested fallback' => ['var(--a, var(--b, #123456))', '#123456'];
        yield 'variable alone' => ['var(--theme-primary)', null];
        yield 'variable with named fallback' => ['var(--x, red)', null];
        yield 'name' => ['red', null];
        yield 'rgb' => ['rgb(0, 0, 0)', null];
        yield 'hex with alpha' => ['#eb054080', null];
        yield 'unsafe' => ['var(--x, #fff);position:fixed', null];
        yield 'not a string' => [12, null];
    }

    #[DataProvider('swatches')]
    public function testSwatch(mixed $colour, ?string $swatch): void
    {
        $this->assertSame($swatch, CssColor::swatch($colour));
    }
}
