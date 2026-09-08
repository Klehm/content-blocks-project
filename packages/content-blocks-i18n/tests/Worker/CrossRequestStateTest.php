<?php

declare(strict_types=1);

namespace ContentBlocks\I18n\Tests\Worker;

use ContentBlocks\Testing\CrossRequestStateScanner;
use PHPUnit\Framework\TestCase;

/**
 * The satellite's half of the worker-mode rule the core states in
 * {@see CrossRequestStateScanner}.
 *
 * This is the package where the rule actually bites. Translation is the one
 * feature here that caches *per request by design* — the prefetch that keeps a
 * 40-block page from issuing 40 SELECTs — and a prefetch cache is exactly the
 * shape of thing that serves one page's French to the next page under a worker.
 * Both caches are resettable, and this test is what keeps a third one from
 * arriving without being.
 */
final class CrossRequestStateTest extends TestCase
{
    /** @var array<class-string, string> */
    private const NOT_A_SHARED_SERVICE = [
        \ContentBlocks\I18n\ContentBlocksI18nBundle::class =>
            'Bundle: $extensionAlias is a compile-time constant of the class, never written during a request.',
    ];

    /** @var array<class-string, string> */
    private const CONTAINER_LIFETIME = [
        \ContentBlocks\I18n\Machine\TranslationProviderRegistry::class =>
            'Indexes the tagged translation providers by name; holds services only.',
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
     * Guards the guard: the two request-scoped caches must be in the scan, and
     * resettable.
     */
    public function testTheRequestScopedCachesAreResettable(): void
    {
        $stateful = $this->scanner()->scan();

        foreach ([\ContentBlocks\I18n\Storage\TranslationStore::class, \ContentBlocks\I18n\Field\FieldMetadataReader::class] as $class) {
            $this->assertArrayHasKey($class, $stateful, sprintf('%s should still be seen as stateful.', $class));
            $this->assertInstanceOf(\ReflectionClass::class, new \ReflectionClass($class));
            $this->assertTrue(
                is_a($class, \Symfony\Contracts\Service\ResetInterface::class, true),
                sprintf('%s caches per request and must implement ResetInterface.', $class),
            );
        }
    }

    /** @return array<class-string, string> */
    private static function declared(): array
    {
        return self::NOT_A_SHARED_SERVICE + self::CONTAINER_LIFETIME;
    }

    private function scanner(): CrossRequestStateScanner
    {
        return new CrossRequestStateScanner(\dirname(__DIR__, 2) . '/src', 'ContentBlocks\\I18n\\');
    }
}
