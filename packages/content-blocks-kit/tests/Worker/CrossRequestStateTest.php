<?php

declare(strict_types=1);

namespace ContentBlocks\Kit\Tests\Worker;

use ContentBlocks\Testing\CrossRequestStateScanner;
use PHPUnit\Framework\TestCase;

/**
 * The kit's half of the worker-mode rule the core states in
 * {@see CrossRequestStateScanner}: nothing here may keep state that outlives a
 * request unless it says why it can.
 *
 * It matters most for block types. A block type is a **shared service** — one
 * instance answers `buildForm()` and `getDefaultData()` for every block of that
 * type on every page of every request — so a block that memoizes anything about
 * the block it last rendered would, under a worker, hand that to the next
 * visitor. The 17 shipped blocks are stateless, and this test is what keeps the
 * eighteenth honest.
 */
final class CrossRequestStateTest extends TestCase
{
    /**
     * @var array<class-string, string>
     */
    private const NOT_A_SHARED_SERVICE = [
        \ContentBlocks\Kit\ContentBlocksKitBundle::class =>
            'Bundle: $blocksConfig is written while the container is compiled, never during a request.',
    ];

    /**
     * Registries that index tagged services once. Same bar as the core: the
     * memoized value derives from service definitions only — no Request, no
     * session, no entity, no locale.
     *
     * @var array<class-string, string>
     */
    private const CONTAINER_LIFETIME = [
        \ContentBlocks\Kit\Icon\IconRegistry::class =>
            'Merges the shipped IconSet with the tagged providers once; the result is markup fixed at build time.',
        \ContentBlocks\Kit\RichText\RichTextEditorRegistry::class =>
            'Indexes the tagged editor adapters by name; holds services only.',
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

    /**
     * Guards the guard, and pins the claim that the shipped blocks are
     * stateless.
     */
    public function testEveryShippedBlockIsScannedAndStateless(): void
    {
        $classes = $this->scanner()->classes();
        $stateful = $this->scanner()->scan();

        $blocks = array_values(array_filter(
            $classes,
            static fn (string $class): bool => str_starts_with($class, 'ContentBlocks\\Kit\\Block\\'),
        ));

        $this->assertGreaterThan(15, \count($blocks), 'The scan should see every shipped block type.');

        foreach ($blocks as $block) {
            $this->assertArrayNotHasKey($block, $stateful, sprintf('%s is a shared service; it must not keep state.', $block));
        }
    }

    /** @return array<class-string, string> */
    private static function declared(): array
    {
        return self::NOT_A_SHARED_SERVICE + self::CONTAINER_LIFETIME;
    }

    private function scanner(): CrossRequestStateScanner
    {
        return new CrossRequestStateScanner(\dirname(__DIR__, 2) . '/src', 'ContentBlocks\\Kit\\');
    }
}
