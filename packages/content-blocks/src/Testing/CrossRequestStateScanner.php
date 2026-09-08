<?php

declare(strict_types=1);

namespace ContentBlocks\Testing;

use Symfony\Contracts\Service\ResetInterface;

/**
 * Finds classes that keep mutable state — the thing that turns into a bug the
 * day an application is served by a worker runtime (FrankenPHP, RoadRunner,
 * Swoole) instead of PHP-FPM.
 *
 * Under a worker the container is booted once and every service instance is
 * reused by every subsequent request, so a property written during one request
 * is still there for the next, under whatever session and locale that one
 * happens to carry. The rule the packages hold themselves to, and that a host
 * can hold its own services to with this scanner, is:
 *
 * > a class that keeps mutable state is either resettable or explicitly
 * > declared as not request-scoped.
 *
 * Resettable means {@see ResetInterface}: autoconfiguration tags it
 * `kernel.reset` and Symfony's `services_resetter` clears it on
 * `kernel.terminate`, the one hook every worker runtime calls. Declared means
 * listed by the caller with the reason it cannot leak — a value object, a
 * compile-time-only bundle class, an index of tagged services that is identical
 * for every request. Writing that reason down is most of the value: it is where
 * "this cache is fine" has to become a sentence someone can disagree with.
 *
 * Usage in a test:
 *
 * ```php
 * $scanner = new CrossRequestStateScanner(__DIR__ . '/../../src', 'App\\');
 * self::assertSame([], $scanner->unaccountedFor([
 *     Foo::class => 'Value object, never a shared service.',
 * ]));
 * ```
 *
 * It reflects over classes; it does not instantiate them.
 */
final class CrossRequestStateScanner
{
    /**
     * @param string       $srcRoot         directory to scan, PSR-4 root of $namespacePrefix
     * @param string       $namespacePrefix namespace $srcRoot maps to, e.g. `App\`
     * @param list<string> $skipDirectories paths under $srcRoot excluded from the scan, relative
     *                                      and slash-separated. Doctrine entities are skipped by
     *                                      default: they are hydrated per request and never shared.
     */
    public function __construct(
        private readonly string $srcRoot,
        private readonly string $namespacePrefix,
        private readonly array $skipDirectories = ['Entity'],
    ) {
    }

    /**
     * Every scanned class that declares at least one mutable property, mapped to
     * those property names.
     *
     * Static properties count: they are worse than instance state, not better.
     * Inherited properties are reported against the class that declares them.
     *
     * @return array<class-string, list<string>>
     */
    public function scan(): array
    {
        $out = [];

        foreach ($this->classes() as $class) {
            $properties = $this->mutablePropertiesOf($class);

            if ($properties !== []) {
                $out[$class] = $properties;
            }
        }

        ksort($out);

        return $out;
    }

    /**
     * Stateful classes that are neither resettable nor declared — the failures.
     *
     * @param array<class-string, string> $declared class => why its state cannot leak
     *
     * @return array<class-string, list<string>> class => offending properties
     */
    public function unaccountedFor(array $declared): array
    {
        $out = [];

        foreach ($this->scan() as $class => $properties) {
            if (isset($declared[$class]) || is_a($class, ResetInterface::class, true)) {
                continue;
            }

            $out[$class] = $properties;
        }

        return $out;
    }

    /**
     * Declarations that no longer describe anything: a class that was deleted,
     * renamed, or has since lost its mutable state. Dropping them is what keeps
     * the list from turning into folklore.
     *
     * @param array<class-string, string> $declared class => why its state cannot leak
     *
     * @return list<class-string>
     */
    public function staleDeclarations(array $declared): array
    {
        $stateful = $this->scan();

        return array_values(array_filter(
            array_keys($declared),
            static fn (string $class): bool => !isset($stateful[$class]),
        ));
    }

    /**
     * @return list<class-string>
     */
    public function classes(): array
    {
        if (!is_dir($this->srcRoot)) {
            throw new \InvalidArgumentException(sprintf('Source root "%s" is not a directory.', $this->srcRoot));
        }

        $root = rtrim($this->srcRoot, '/');
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
        );

        $out = [];

        foreach ($files as $file) {
            \assert($file instanceof \SplFileInfo);

            if ($file->getExtension() !== 'php') {
                continue;
            }

            $relative = str_replace('\\', '/', substr($file->getPathname(), \strlen($root) + 1));

            foreach ($this->skipDirectories as $skip) {
                if (str_starts_with($relative, trim($skip, '/') . '/')) {
                    continue 2;
                }
            }

            $class = rtrim($this->namespacePrefix, '\\') . '\\'
                . str_replace('/', '\\', substr($relative, 0, -\strlen('.php')));

            if (class_exists($class) || trait_exists($class)) {
                $out[] = $class;
            }
        }

        sort($out);

        return $out;
    }

    /**
     * @param class-string $class
     *
     * @return list<string>
     */
    private function mutablePropertiesOf(string $class): array
    {
        $reflection = new \ReflectionClass($class);

        if ($reflection->isInterface() || $reflection->isEnum()) {
            return [];
        }

        $out = [];

        foreach ($reflection->getProperties() as $property) {
            if ($property->getDeclaringClass()->getName() !== $class || $property->isReadOnly()) {
                continue;
            }

            $out[] = $property->getName();
        }

        return $out;
    }
}
