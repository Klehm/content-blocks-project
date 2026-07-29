<?php

declare(strict_types=1);

namespace ContentBlocks\Tests\Form\Extension;

use ContentBlocks\Form\Extension\AsBlockFormExtension;
use PHPUnit\Framework\TestCase;

final class AsBlockFormExtensionTest extends TestCase
{
    public function testNoArgumentsMeansGlobalWildcard(): void
    {
        $attribute = new AsBlockFormExtension();

        $this->assertSame(['*'], $attribute->blockTypes);
        $this->assertSame(0, $attribute->priority);
    }

    public function testSingleStringIsNormalizedToAList(): void
    {
        $attribute = new AsBlockFormExtension('button');

        $this->assertSame(['button'], $attribute->blockTypes);
    }

    public function testArrayOfTypesIsKept(): void
    {
        $attribute = new AsBlockFormExtension(['button', 'card']);

        $this->assertSame(['button', 'card'], $attribute->blockTypes);
    }

    public function testEmptyArrayFallsBackToWildcard(): void
    {
        $attribute = new AsBlockFormExtension([]);

        $this->assertSame(['*'], $attribute->blockTypes);
    }

    public function testPriorityIsCaptured(): void
    {
        $attribute = new AsBlockFormExtension('button', priority: 10);

        $this->assertSame(['button'], $attribute->blockTypes);
        $this->assertSame(10, $attribute->priority);
    }
}
