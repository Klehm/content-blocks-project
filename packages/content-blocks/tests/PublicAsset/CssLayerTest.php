<?php

declare(strict_types=1);

namespace ContentBlocks\Tests\PublicAsset;

use ContentBlocks\PublicAsset\CssLayer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CssLayerTest extends TestCase
{
    public function testNoLayerLeavesTheCssUntouched(): void
    {
        $this->assertSame('.a{color:red}', (new CssLayer())->wrap('.a{color:red}'));
    }

    public function testALayerWrapsTheWholeStylesheet(): void
    {
        $this->assertSame(
            "@layer vendor.cb {\n.a{color:red}\n}\n",
            (new CssLayer('vendor.cb'))->wrap('.a{color:red}'),
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidNames(): iterable
    {
        yield 'empty' => [''];
        yield 'a space' => ['content blocks'];
        yield 'a brace' => ['cb} body{display:none'];
        yield 'a leading digit' => ['1cb'];
        yield 'an empty segment' => ['vendor..cb'];
    }

    // The name is written into CSS: anything else could break out of it.
    #[DataProvider('invalidNames')]
    public function testANameThatIsNotALayerNameIsRefused(string $name): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new CssLayer($name);
    }
}
