<?php

declare(strict_types=1);

namespace ContentBlocks\Tests\Worker;

use ContentBlocks\Testing\CrossRequestStateScanner;
use PHPUnit\Framework\TestCase;

/**
 * Nothing in this package may keep state that outlives a request unless it says
 * why it can. See {@see CrossRequestStateScanner} for the rule and the reason
 * it exists; this test is that rule applied to `content-blocks/src`.
 *
 * A service with an undeclared cache fails here rather than producing a bug that
 * only reproduces on the third page view of a FrankenPHP deployment.
 */
final class CrossRequestStateTest extends TestCase
{
    /**
     * Objects that are never a shared service: value objects, per-operation
     * tallies, UX components (a fresh instance per render). Their state cannot
     * outlive a request because the instance does not.
     *
     * @var array<class-string, string>
     */
    private const NOT_A_SHARED_SERVICE = [
        \ContentBlocks\Block\BlockRestoreTally::class =>
            'Per-operation tally: the publisher constructs one per restore and drops it.',
        \ContentBlocks\Twig\Component\BlockComponent::class =>
            'Live Component: the component factory builds a fresh instance for every render.',
    ];

    /**
     * Services whose state is built from the container and is identical for
     * every request of the process. Indexing tagged services once is exactly
     * what a worker should do; resetting them would rebuild the same map.
     *
     * The bar for this list: the memoized value must derive from service
     * definitions or configuration only. Anything touching a Request, a session,
     * an entity or a locale belongs in ResetInterface instead.
     *
     * @var array<class-string, string>
     */
    private const CONTAINER_LIFETIME = [
        \ContentBlocks\BlockType\BlockTypeRegistry::class =>
            'BlockTypeCompilerPass fills it through addMethodCall(), so the map is written at instantiation and holds services only.',
        \ContentBlocks\Versioning\EnvelopeUpgradeChain::class =>
            'Indexes the injected upgraders in the constructor; holds services only.',
        \ContentBlocks\Form\Extension\BlockFormExtensionCollection::class =>
            'Materializes its tagged iterator in the constructor; holds services only.',
    ];

    public function testNoUndeclaredCrossRequestState(): void
    {
        $unaccounted = $this->scanner()->unaccountedFor(self::declared());

        $this->assertSame(
            [],
            $unaccounted,
            sprintf(
                "These classes keep mutable state but are neither resettable nor declared:\n%s\n"
                . "Under a worker runtime that state survives into the next request.\n"
                . 'Implement Symfony\Contracts\Service\ResetInterface, or declare the class in %s with the reason it cannot leak.',
                implode("\n", array_map(
                    static fn (string $class, array $props): string => sprintf('  %s: $%s', $class, implode(', $', $props)),
                    array_keys($unaccounted),
                    $unaccounted,
                )),
                self::class,
            ),
        );
    }

    public function testDeclarationsStillDescribeStatefulClasses(): void
    {
        $this->assertSame(
            [],
            $this->scanner()->staleDeclarations(self::declared()),
            'Declared here but no longer stateful (or gone) — drop the entry.',
        );

        foreach (self::declared() as $class => $reason) {
            $this->assertNotSame('', trim($reason), sprintf('%s is declared without a reason.', $class));
        }
    }

    /** Guards the guard: a scan that silently found nothing would pass forever. */
    public function testScannerSeesThePackage(): void
    {
        $this->assertContains(\ContentBlocks\Rendering\BlockRenderer::class, $this->scanner()->classes());
        $this->assertNotSame([], $this->scanner()->scan());
    }

    /** @return array<class-string, string> */
    private static function declared(): array
    {
        return self::NOT_A_SHARED_SERVICE + self::CONTAINER_LIFETIME;
    }

    private function scanner(): CrossRequestStateScanner
    {
        return new CrossRequestStateScanner(\dirname(__DIR__, 2) . '/src', 'ContentBlocks\\');
    }
}
