<?php

declare(strict_types=1);

namespace ContentBlocks\Kit\Tests\Packaging;

use PHPUnit\Framework\TestCase;

/**
 * Yarn 1 refuses a `file:` dependency whose package.json has no version,
 * which is how hosts link `assets/` from vendor.
 */
final class AssetPackageVersionTest extends TestCase
{
    public function testTheAssetPackageDeclaresAVersion(): void
    {
        $manifest = json_decode((string) file_get_contents(\dirname(__DIR__, 2) . '/assets/package.json'), true);

        $this->assertIsArray($manifest);
        $this->assertIsString($manifest['version'] ?? null, 'assets/package.json needs a "version"');
        $this->assertMatchesRegularExpression('/^\d+\.\d+\.\d+(-[0-9A-Za-z.-]+)?$/', $manifest['version']);
    }

    /** Bumped with each release, so it never trails the last tag. */
    public function testTheVersionIsNotOlderThanTheLastRelease(): void
    {
        $manifest = json_decode((string) file_get_contents(\dirname(__DIR__, 2) . '/assets/package.json'), true);
        $changelog = (string) file_get_contents(\dirname(__DIR__, 2) . '/CHANGELOG.md');
        preg_match('/^## \[(\d+\.\d+\.\d+[^\]]*)\]/m', $changelog, $released);

        $this->assertNotEmpty($released, 'no released version in CHANGELOG.md');
        $this->assertTrue(
            version_compare((string) ($manifest['version'] ?? '0'), $released[1], '>='),
            sprintf('assets/package.json is at %s, behind the %s release', $manifest['version'] ?? '?', $released[1]),
        );
    }
}
