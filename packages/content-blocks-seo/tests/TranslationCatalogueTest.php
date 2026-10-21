<?php

declare(strict_types=1);

namespace ContentBlocks\Seo\Tests;

use ContentBlocks\Seo\SeoChecks;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

final class TranslationCatalogueTest extends TestCase
{
    public function testEveryKeyThePanelReadsIsInBothCatalogues(): void
    {
        $template = (string) file_get_contents(\dirname(__DIR__) . '/templates/panel.html.twig');
        preg_match_all("/'(cb_seo\.[a-z0-9_.]+)'\|trans/", $template, $used);

        foreach (['en', 'fr'] as $locale) {
            $keys = $this->keys($locale);
            foreach (array_unique($used[1]) as $key) {
                $this->assertContains($key, $keys, \sprintf('%s is missing from the %s catalogue.', $key, $locale));
            }
        }
    }

    public function testEachCheckHasAMessageAndAHint(): void
    {
        foreach (SeoChecks::ALL as $check) {
            foreach (['check', 'hint'] as $kind) {
                $this->assertContains("cb_seo.$kind.$check", $this->keys('en'));
            }
        }
    }

    public function testTheCataloguesHaveTheSameKeys(): void
    {
        $this->assertSame($this->keys('en'), $this->keys('fr'));
    }

    /** @return list<string> */
    private function keys(string $locale): array
    {
        $tree = Yaml::parseFile(\dirname(__DIR__) . "/translations/content_blocks_seo.$locale.yaml");
        $keys = [];
        $walk = static function (array $node, string $prefix) use (&$walk, &$keys): void {
            foreach ($node as $key => $value) {
                \is_array($value) ? $walk($value, "$prefix$key.") : $keys[] = "$prefix$key";
            }
        };
        $walk($tree, '');
        sort($keys);

        return $keys;
    }
}
