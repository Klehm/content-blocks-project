<?php

declare(strict_types=1);

namespace ContentBlocks\Sylius\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/** Every key the English catalogue has, French has too, and the reverse. */
final class TranslationCatalogueTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function domains(): iterable
    {
        yield 'messages' => ['messages'];
        yield 'content_blocks' => ['content_blocks'];
    }

    #[DataProvider('domains')]
    public function testTheLocalesShareTheirKeys(string $domain): void
    {
        $dir = \dirname(__DIR__, 2) . '/translations/';

        self::assertSame(
            self::keys(Yaml::parseFile($dir . $domain . '.en.yaml')),
            self::keys(Yaml::parseFile($dir . $domain . '.fr.yaml')),
        );
    }

    /** @return list<string> */
    private static function keys(mixed $tree, string $prefix = ''): array
    {
        if (!\is_array($tree)) {
            return [$prefix];
        }
        $keys = [];
        foreach ($tree as $key => $value) {
            $keys = [...$keys, ...self::keys($value, $prefix === '' ? (string) $key : $prefix . '.' . $key)];
        }
        sort($keys);

        return $keys;
    }
}
