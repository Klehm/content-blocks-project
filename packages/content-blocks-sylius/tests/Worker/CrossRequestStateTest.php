<?php

declare(strict_types=1);

namespace ContentBlocks\Sylius\Tests\Worker;

use ContentBlocks\Testing\CrossRequestStateScanner;
use PHPUnit\Framework\TestCase;

/**
 * The plugin's half of the worker-mode rule the core states in
 * {@see CrossRequestStateScanner}.
 */
final class CrossRequestStateTest extends TestCase
{
    /** @var array<class-string, string> */
    private const NOT_A_SHARED_SERVICE = [
        \ContentBlocks\Sylius\ContentBlocksSyliusPlugin::class =>
            'Bundle: $extensionAlias is a compile-time constant of the class, never written during a request.',
        \ContentBlocks\Sylius\Form\ChoiceList\CatalogCodeLoader::class =>
            'Built per form field by CatalogCodeType, never a container service.',
    ];

    /** @var array<class-string, string> */
    private const CONTAINER_LIFETIME = [];

    public function testNoUndeclaredCrossRequestState(): void
    {
        $unaccounted = $this->scanner()->unaccountedFor(self::declared());

        $this->assertSame([], $unaccounted, sprintf(
            "These classes keep mutable state but are neither resettable nor declared:\n%s",
            implode("\n", array_map(
                static fn (string $class, array $props): string => sprintf('  %s: $%s', $class, implode(', $', $props)),
                array_keys($unaccounted),
                $unaccounted,
            )),
        ));
    }

    public function testDeclarationsStillDescribeStatefulClasses(): void
    {
        $this->assertSame([], $this->scanner()->staleDeclarations(self::declared()));
    }

    /** @return array<class-string, string> */
    private static function declared(): array
    {
        return self::NOT_A_SHARED_SERVICE + self::CONTAINER_LIFETIME;
    }

    private function scanner(): CrossRequestStateScanner
    {
        return new CrossRequestStateScanner(\dirname(__DIR__, 2) . '/src', 'ContentBlocks\\Sylius\\');
    }
}
