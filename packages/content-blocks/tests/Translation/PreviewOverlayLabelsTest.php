<?php

declare(strict_types=1);

namespace ContentBlocks\Tests\Translation;

use PHPUnit\Framework\TestCase;

/**
 * The preview overlay is the one piece of UI with no Stimulus element to hang
 * `data-i18n-*` attributes off: it runs as a plain module inside the preview
 * iframe, so every string it renders has to be translated in
 * `render/content_area.html.twig` and handed over on `window.__cbOverlayLabels`.
 *
 * That indirection is exactly what let "Duplicate", "Delete", "Move up", "Move
 * down" and "Save as template" sit hardcoded in the overlay for as long as they
 * did — nothing tied the two files together. These tests are that tie:
 *
 *  - every key the overlay asks for is actually emitted by the template;
 *  - every key the template emits resolves in both catalogs;
 *  - the overlay builds no button from a bare string literal.
 */
final class PreviewOverlayLabelsTest extends TestCase
{
    private const OVERLAY = __DIR__ . '/../../assets/preview-overlay.js';
    private const TEMPLATE = __DIR__ . '/../../templates/render/content_area.html.twig';
    private const CATALOG_DIR = __DIR__ . '/../../translations';

    /** @return list<string> keys read via `t('key', …)` in the overlay */
    private function keysRequestedByOverlay(): array
    {
        $js = (string) file_get_contents(self::OVERLAY);
        preg_match_all("/\bt\(\s*'([a-z_]+)'/", $js, $m);

        return array_values(array_unique($m[1]));
    }

    /** @return array<string, string> label key => translation key emitted by the template */
    private function keysEmittedByTemplate(): array
    {
        $twig = (string) file_get_contents(self::TEMPLATE);
        if (!preg_match('/__cbOverlayLabels = \{\{ \{(.+?)\}\|json_encode/s', $twig, $block)) {
            self::fail('The template no longer emits window.__cbOverlayLabels as an inline map.');
        }
        preg_match_all("/([a-z_]+):\s*'([^']+)'\|trans/", $block[1], $m, PREG_SET_ORDER);

        $out = [];
        foreach ($m as $pair) {
            $out[$pair[1]] = $pair[2];
        }

        return $out;
    }

    public function testTheOverlayFindsEveryLabelItAsksFor(): void
    {
        $requested = $this->keysRequestedByOverlay();
        $this->assertNotEmpty($requested, 'No t() call found — did the overlay stop using the label channel?');

        $emitted = $this->keysEmittedByTemplate();
        foreach ($requested as $key) {
            $this->assertArrayHasKey(
                $key,
                $emitted,
                sprintf('The overlay reads "%s" but content_area.html.twig never sends it, so it renders its English fallback.', $key),
            );
        }
    }

    public function testEveryEmittedLabelResolvesInBothCatalogs(): void
    {
        $emitted = $this->keysEmittedByTemplate();
        $this->assertNotEmpty($emitted);

        foreach (['en', 'fr'] as $locale) {
            $catalog = $this->catalog($locale);
            foreach ($emitted as $label => $key) {
                $this->assertArrayHasKey($key, $catalog, "Overlay label '$label' points at missing '$key' in '$locale'");
            }
        }
    }

    /**
     * Guards the habit, not just today's strings: a new toolbar button added
     * with a literal title would ship untranslated and no other test would say
     * a word.
     */
    public function testNoToolbarButtonIsBuiltFromABareLiteral(): void
    {
        $js = (string) file_get_contents(self::OVERLAY);

        // makeBtn(glyph, title, …) — the title argument must be a t() call.
        preg_match_all('/makeBtn\(\s*\'[^\']*\'\s*,\s*([^,]+),/', $js, $m);
        $this->assertNotEmpty($m[1], 'No makeBtn() call found — has the toolbar been rewritten?');

        foreach ($m[1] as $titleArg) {
            $this->assertStringStartsWith(
                't(',
                trim($titleArg),
                sprintf('Toolbar button title %s is a literal; route it through t() or it ships untranslated.', trim($titleArg)),
            );
        }
    }

    /** @return array<string, string> */
    private function catalog(string $locale): array
    {
        $path = self::CATALOG_DIR . "/content_blocks.$locale.yaml";
        $this->assertFileExists($path);

        $out = [];
        /** @var list<array{int, string}> $stack */
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
            $prefix = implode('.', array_map(static fn (array $p): string => $p[1], $stack));
            $full = $prefix === '' ? $key : "$prefix.$key";

            if ($rest === '') {
                $stack[] = [$indent, $key];
            } else {
                $out[$full] = trim($rest, "\"'");
            }
        }

        return $out;
    }
}
