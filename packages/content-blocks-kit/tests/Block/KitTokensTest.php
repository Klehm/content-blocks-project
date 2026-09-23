<?php

declare(strict_types=1);

namespace ContentBlocks\Kit\Tests\Block;

use PHPUnit\Framework\TestCase;

/**
 * The documented theming tokens are declared once, on `:where(:root)`, and
 * never again on a component: a redeclaration there beat the host's override.
 */
final class KitTokensTest extends TestCase
{
    private const TOKENS = [
        '--cb-kit-primary', '--cb-kit-primary-contrast', '--cb-kit-secondary',
        '--cb-kit-secondary-contrast', '--cb-kit-border', '--cb-kit-text',
        '--cb-kit-radius', '--cb-kit-tabs-line', '--cb-kit-tabs-tab',
        '--cb-kit-tabs-tab-active', '--cb-kit-tabs-panel-bg',
        '--cb-kit-tabs-accent',
    ];

    public function testEveryTokenIsDeclaredOnTheRootOnly(): void
    {
        $declared = [];

        foreach ($this->rules() as [$selector, $body]) {
            foreach (self::TOKENS as $token) {
                if (preg_match('/(^|[;{\s])' . preg_quote($token, '/') . '\s*:/', $body) !== 1) {
                    continue;
                }
                $this->assertSame(':where(:root)', $selector, "$token redeclared on $selector");
                $declared[] = $token;
            }
        }

        $this->assertEqualsCanonicalizing(self::TOKENS, $declared);
    }

    // The documented reach of --cb-kit-border.
    public function testTheBorderTokenDrivesTheRules(): void
    {
        $bySelector = [];
        foreach ($this->rules() as [$selector, $body]) {
            $bySelector[$selector] = $body;
        }

        foreach (['.cb-kit-divider', '.cb-kit-card', '.cb-kit-accordion', '.cb-kit-table th, .cb-kit-table td'] as $selector) {
            $this->assertStringContainsString('var(--cb-kit-border', $bySelector[$selector] ?? '', $selector);
        }
    }

    /** @return list<array{string, string}> selector, declarations */
    private function rules(): array
    {
        $css = (string) file_get_contents(\dirname(__DIR__, 2) . '/assets/styles/kit.css');
        $css = (string) preg_replace('#/\*.*?\*/#s', '', $css);
        preg_match_all('/([^{}]+)\{([^{}]*)\}/', $css, $m, PREG_SET_ORDER);

        return array_map(
            static fn (array $r): array => [trim((string) preg_replace('/\s+/', ' ', $r[1])), $r[2]],
            $m,
        );
    }
}
