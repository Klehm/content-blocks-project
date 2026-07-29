<?php

declare(strict_types=1);

namespace ContentBlocks\Tests\Versioning;

use ContentBlocks\Versioning\DenyOnMismatchUpgrader;
use ContentBlocks\Versioning\IncompatibleContentVersionException;
use PHPUnit\Framework\TestCase;

final class DenyOnMismatchUpgraderTest extends TestCase
{
    public function testTheCurrentGenerationPassesThroughUntouched(): void
    {
        $payload = ['format' => 'x', 'columns' => []];

        $this->assertTrue((new DenyOnMismatchUpgrader())->supports(4, 4));
        $this->assertSame($payload, (new DenyOnMismatchUpgrader())->upgrade($payload, 4, 4));
    }

    public function testAKnownMismatchIsRefused(): void
    {
        // Something changed between generation 3 and 4 and only the host knows
        // what; replaying the payload blind is how content quietly rots.
        $upgrader = new DenyOnMismatchUpgrader();

        $this->assertFalse($upgrader->supports(3, 4));

        try {
            $upgrader->upgrade([], 3, 4);
            $this->fail('Expected IncompatibleContentVersionException.');
        } catch (IncompatibleContentVersionException $e) {
            $this->assertSame(3, $e->getStoredVersion());
            $this->assertSame(4, $e->getCurrentVersion());
        }
    }

    public function testAGenerationAheadOfTheAppIsRefusedToo(): void
    {
        // Content written by a newer deploy that has since been rolled back.
        $this->assertFalse((new DenyOnMismatchUpgrader())->supports(5, 4));
    }

    public function testUnknownVersionsAreAccepted(): void
    {
        // Every row written before versioning existed carries null. Refusing
        // those would make a host's whole template library unusable the day
        // they upgrade — null means "no information", not "wrong".
        $upgrader = new DenyOnMismatchUpgrader();
        $payload = ['columns' => []];

        $this->assertTrue($upgrader->supports(null, 4));
        $this->assertSame($payload, $upgrader->upgrade($payload, null, 4));
    }
}
