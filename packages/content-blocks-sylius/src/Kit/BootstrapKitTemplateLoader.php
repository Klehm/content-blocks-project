<?php

declare(strict_types=1);

namespace ContentBlocks\Sylius\Kit;

use Twig\Error\LoaderError;
use Twig\Loader\LoaderInterface;
use Twig\Source;

/**
 * Serves the plugin's Bootstrap view for a kit block ahead of the kit's own,
 * yet behind a host's templates/bundles/ContentBlocksKitBundle/ override.
 *
 * @see docs/internals/sylius.md#bootstrap-markup-for-the-kit
 */
final class BootstrapKitTemplateLoader implements LoaderInterface
{
    private const PREFIX = '@ContentBlocksKit/';

    public function __construct(
        private readonly string $markup,
        private readonly string $templatesDir,
        private readonly string $overridesDir,
    ) {
    }

    public function getSourceContext(string $name): Source
    {
        $path = $this->find($name);
        if ($path === null) {
            throw new LoaderError(sprintf('Template "%s" has no Bootstrap variant.', $name));
        }

        return new Source((string) file_get_contents($path), $name, $path);
    }

    public function getCacheKey(string $name): string
    {
        $path = $this->find($name);
        if ($path === null) {
            throw new LoaderError(sprintf('Template "%s" has no Bootstrap variant.', $name));
        }

        return $path;
    }

    public function isFresh(string $name, int $time): bool
    {
        $path = $this->find($name);

        return $path !== null && filemtime($path) < $time;
    }

    public function exists(string $name): bool
    {
        return $this->find($name) !== null;
    }

    private function find(string $name): ?string
    {
        if ($this->markup !== 'bootstrap' || !str_starts_with($name, self::PREFIX)) {
            return null;
        }
        $relative = substr($name, \strlen(self::PREFIX));
        if (str_contains($relative, '..')) {
            return null;
        }
        $override = $this->overridesDir . '/bundles/ContentBlocksKitBundle/' . $relative;
        $path = $this->templatesDir . '/' . $relative;

        return !is_file($override) && is_file($path) ? $path : null;
    }
}
