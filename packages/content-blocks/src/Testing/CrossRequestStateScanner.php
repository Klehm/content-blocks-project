<?php

declare(strict_types=1);

namespace ContentBlocks\Testing;

use Symfony\Contracts\Service\ResetInterface;

/**
 * Finds classes keeping mutable state, which under a worker runtime is handed
 * to the next visitor. Reflects over classes, never instantiates them.
 *
 * @see docs/internals/worker-mode.md#the-scanner
 */
final class CrossRequestStateScanner
{
    /**
     * @param string       $srcRoot         PSR-4 root of $namespacePrefix
     * @param string       $namespacePrefix namespace it maps to, e.g. `App\`
     * @param list<string> $skipDirectories relative, slash-separated; entities
     *                                      are skipped by default
     */
    public function __construct(
        private readonly string $srcRoot,
        private readonly string $namespacePrefix,
        private readonly array $skipDirectories = ['Entity'],
    ) {
    }

    /**
     * Every class declaring a mutable property, mapped to those names. Static
     * properties count — they are worse than instance state, not better.
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
     * Stateful classes neither resettable nor declared — the failures.
     *
     * @param array<class-string, string> $declared why its state cannot leak
     *
     * @return array<class-string, list<string>> offending properties
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
     * Declarations that no longer describe anything. Dropping them is what
     * keeps the list from turning into folklore.
     *
     * @param array<class-string, string> $declared why its state cannot leak
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
