<?php

declare(strict_types=1);

namespace ContentBlocks\Tests\Versioning;

use ContentBlocks\Versioning\EnvelopeUpgradeChain;
use ContentBlocks\Versioning\EnvelopeUpgraderInterface;
use PHPUnit\Framework\TestCase;

/**
 * The chain ships empty, so these tests are the only thing standing between the
 * mechanism and rot: they stage the steps a future format bump would add, and
 * assert the walk does what the next person will assume it does.
 */
final class EnvelopeUpgradeChainTest extends TestCase
{
    private function step(string $from, string $to, ?\Closure $transform = null): EnvelopeUpgraderInterface
    {
        return new class($from, $to, $transform) implements EnvelopeUpgraderInterface {
            public function __construct(
                private readonly string $from,
                private readonly string $to,
                private readonly ?\Closure $transform,
            ) {
            }

            public function upgradesFrom(): string
            {
                return $this->from;
            }

            public function upgradesTo(): string
            {
                return $this->to;
            }

            public function upgrade(array $payload): array
            {
                return $this->transform === null ? $payload : ($this->transform)($payload);
            }
        };
    }

    public function testTheCurrentFormatNeedsNoStep(): void
    {
        // The empty-chain case, which is every call today.
        $chain = new EnvelopeUpgradeChain();
        $payload = ['format' => 'cb/v1', 'columns' => []];

        $this->assertTrue($chain->supports('cb/v1', 'cb/v1'));
        $this->assertSame($payload, $chain->upgrade($payload, 'cb/v1', 'cb/v1'));
    }

    public function testAnUnknownFormatHasNoPath(): void
    {
        $chain = new EnvelopeUpgradeChain();

        $this->assertFalse($chain->supports('cb/v0', 'cb/v1'));
    }

    public function testASingleStepBridgesOneBump(): void
    {
        $chain = new EnvelopeUpgradeChain([
            $this->step('cb/v1', 'cb/v2', fn (array $p) => ['sections' => $p['rows'], 'format' => $p['format']]),
        ]);

        $this->assertTrue($chain->supports('cb/v1', 'cb/v2'));

        $upgraded = $chain->upgrade(['format' => 'cb/v1', 'rows' => ['a', 'b']], 'cb/v1', 'cb/v2');

        $this->assertSame(['a', 'b'], $upgraded['sections']);
        $this->assertSame('cb/v2', $upgraded['format'], 'the walk restamps the format');
    }

    public function testStepsChainAcrossSeveralBumps(): void
    {
        // v1 → v2 → v3 with only single-hop steps declared: the point of a
        // chain is that a host upgrading from far back needs no special case.
        $chain = new EnvelopeUpgradeChain([
            $this->step('cb/v2', 'cb/v3', fn (array $p) => $p + ['third' => true]),
            $this->step('cb/v1', 'cb/v2', fn (array $p) => $p + ['second' => true]),
        ]);

        $this->assertTrue($chain->supports('cb/v1', 'cb/v3'));

        $upgraded = $chain->upgrade(['format' => 'cb/v1'], 'cb/v1', 'cb/v3');

        $this->assertTrue($upgraded['second'], 'first hop ran');
        $this->assertTrue($upgraded['third'], 'second hop ran');
        $this->assertSame('cb/v3', $upgraded['format']);
    }

    public function testAChainThatStopsShortIsNoPathAtAll(): void
    {
        // v1 → v2 exists but nothing reaches v3: refusing is the only honest
        // answer, since a half-upgraded payload is worse than none.
        $chain = new EnvelopeUpgradeChain([$this->step('cb/v1', 'cb/v2')]);

        $this->assertFalse($chain->supports('cb/v1', 'cb/v3'));
        $this->expectException(\LogicException::class);
        $chain->upgrade(['format' => 'cb/v1'], 'cb/v1', 'cb/v3');
    }

    public function testACycleDoesNotHang(): void
    {
        // Host-supplied steps can be wrong; a walk must not turn into a loop.
        $chain = new EnvelopeUpgradeChain([
            $this->step('cb/v1', 'cb/v2'),
            $this->step('cb/v2', 'cb/v1'),
        ]);

        $this->assertFalse($chain->supports('cb/v1', 'cb/v9'));
    }

    public function testTheLastStepRegisteredForASourceWins(): void
    {
        // Same override semantics as any other service, so a host can replace a
        // shipped step.
        $chain = new EnvelopeUpgradeChain([
            $this->step('cb/v1', 'cb/v2', fn (array $p) => $p + ['by' => 'shipped']),
            $this->step('cb/v1', 'cb/v2', fn (array $p) => $p + ['by' => 'host']),
        ]);

        $this->assertSame('host', $chain->upgrade(['format' => 'cb/v1'], 'cb/v1', 'cb/v2')['by']);
    }
}
