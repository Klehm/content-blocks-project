<?php

declare(strict_types=1);

namespace ContentBlocks\Tests\Translation;

use PHPUnit\Framework\TestCase;

/**
 * Guards the translation catalogs against the drift that let a renamed key ship
 * untranslated: the layout picker referenced `cb.section.layout.*` while the
 * catalog still only defined the superseded `cb.styling.layout.*`, so the
 * buttons rendered their raw keys.
 *
 * Two invariants, checked without a YAML dependency (the catalogs are plain
 * nested maps of quoted string leaves, so a tiny indent parser suffices):
 *  - EN and FR expose the exact same set of keys (add a key to one locale and
 *    the test fails until the other catches up);
 *  - no value is blank;
 *  - the keys the UI actually references resolve (regression anchor).
 */
final class TranslationCatalogTest extends TestCase
{
    private const DIR = __DIR__ . '/../../translations';

    public function testEnAndFrExposeTheSameKeys(): void
    {
        $en = array_keys($this->parse(self::DIR . '/content_blocks.en.yaml'));
        $fr = array_keys($this->parse(self::DIR . '/content_blocks.fr.yaml'));
        sort($en);
        sort($fr);

        $this->assertSame(
            $en,
            $fr,
            "EN/FR key sets diverged.\n  only in EN: " . implode(', ', array_diff($en, $fr))
                . "\n  only in FR: " . implode(', ', array_diff($fr, $en)),
        );
    }

    public function testNoValueIsBlank(): void
    {
        foreach (['en', 'fr'] as $locale) {
            foreach ($this->parse(self::DIR . "/content_blocks.$locale.yaml") as $key => $value) {
                $this->assertNotSame('', trim($value), "Empty '$locale' translation for '$key'");
            }
        }
    }

    /**
     * Keys the templates / form types reference by literal — if one is dropped
     * or renamed in the catalog again, this fails loudly.
     */
    public function testReferencedKeysResolveInBothLocales(): void
    {
        $required = [
            'cb.section.layout.full',
            'cb.section.layout.two_cols',
            'cb.section.layout.three_cols',
            'cb.upload.file',
            'cb.styling.palette.none',
            'cb.styling.palette.custom',
        ];

        foreach (['en', 'fr'] as $locale) {
            $catalog = $this->parse(self::DIR . "/content_blocks.$locale.yaml");
            foreach ($required as $key) {
                $this->assertArrayHasKey($key, $catalog, "Missing '$key' in '$locale' catalog");
            }
        }
    }

    /**
     * Flatten a simple nested YAML map into dotted keys. Handles the two line
     * shapes the catalogs use: `key:` (a nested map) and `key: "value"` (a leaf).
     * Leaf keys may themselves contain dots (e.g. `width.full:`), which are kept.
     *
     * @return array<string, string>
     */
    private function parse(string $path): array
    {
        $this->assertFileExists($path);
        $out = [];
        /** @var list<array{int, string}> $stack indent → key */
        $stack = [];

        foreach (file($path, \FILE_IGNORE_NEW_LINES) as $line) {
            $trimmed = trim($line);
            if ($trimmed === '' || str_starts_with($trimmed, '#')) {
                continue;
            }
            $indent = \strlen($line) - \strlen(ltrim($line, ' '));
            $colon = strpos($line, ':');
            $key = trim(substr($line, 0, $colon));
            $rest = trim(substr($line, $colon + 1));

            while ($stack !== [] && $stack[\count($stack) - 1][0] >= $indent) {
                array_pop($stack);
            }
            $path_ = implode('.', array_map(static fn (array $p): string => $p[1], $stack));
            $full = $path_ === '' ? $key : "$path_.$key";

            if ($rest === '') {
                $stack[] = [$indent, $key];
            } else {
                $out[$full] = trim($rest, "\"'");
            }
        }

        return $out;
    }
}
