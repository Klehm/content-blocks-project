<?php

declare(strict_types=1);

namespace ContentBlocks\Seo\Tests\Worker;

use ContentBlocks\Testing\CrossRequestStateScanner;
use PHPUnit\Framework\TestCase;

/**
 * The satellite's half of the worker-mode rule the core states in
 * {@see CrossRequestStateScanner}.
 */
final class CrossRequestStateTest extends TestCase
{
    /** @var array<class-string, string> */
    private const NOT_A_SHARED_SERVICE = [
        \ContentBlocks\Seo\ContentBlocksSeoBundle::class =>
            'Bundle: $extensionAlias is a compile-time constant of the class, never written during a request.',
    ];

    /** @var array<class-string, string> */
    private const CONTAINER_LIFETIME = [];

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

    /** @return array<class-string, string> */
    private static function declared(): array
    {
        return self::NOT_A_SHARED_SERVICE + self::CONTAINER_LIFETIME;
    }

    private function scanner(): CrossRequestStateScanner
    {
        return new CrossRequestStateScanner(\dirname(__DIR__, 2) . '/src', 'ContentBlocks\\Seo\\');
    }
}
