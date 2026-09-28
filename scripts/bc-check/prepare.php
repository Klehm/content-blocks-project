<?php

/**
 * Turns a package copy into what roave compares: the promised surface only.
 *
 * @see docs/internals/bc-check.md
 *
 * Usage: php prepare.php <package-copy> <bc-page.md> [<core-copy>]
 */

declare(strict_types=1);

[, $dir, $page] = $argv + [null, null, null];
$core = $argv[3] ?? null;
if (!is_dir("$dir/src") || !is_file((string) $page)) {
    fwrite(STDERR, "usage: php prepare.php <package-copy> <bc-page.md> [<core-copy>]\n");
    exit(2);
}

// The kit and i18n path-repo the core; point them at the core copy instead.
if (null !== $core) {
    $json = json_decode((string) file_get_contents("$dir/composer.json"), true, flags: JSON_THROW_ON_ERROR);
    $json['repositories'] = [[
        'type' => 'path',
        'url' => realpath($core),
        'options' => ['symlink' => false, 'versions' => ['klehm/content-blocks' => 'dev-bc-check']],
    ]];
    $json['require']['klehm/content-blocks'] = 'dev-bc-check';
    file_put_contents("$dir/composer.json", json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

$promised = promisedNames((string) file_get_contents($page));
$marked = 0;
$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator("$dir/src", FilesystemIterator::SKIP_DOTS));
foreach ($files as $file) {
    if ('php' !== $file->getExtension()) {
        continue;
    }
    $source = (string) file_get_contents($file->getPathname());
    $result = markInternal($source, $promised);
    if (null !== $result) {
        file_put_contents($file->getPathname(), $result);
        ++$marked;
    }
}
fwrite(STDERR, sprintf("%s: %d class-likes outside the promise marked @internal\n", basename(realpath($dir)), $marked));

/**
 * Every capitalised identifier in backticks under "What is covered".
 *
 * @return array<string, true>
 */
function promisedNames(string $markdown): array
{
    if (!preg_match('/^## What is covered$(.*?)^## /ms', $markdown, $covered)) {
        fwrite(STDERR, "No \"## What is covered\" section in the BC page.\n");
        exit(2);
    }
    preg_match_all('/`([^`\n]+)`/', $covered[1], $code);
    preg_match_all('/\b[A-Z][A-Za-z0-9]+\b/', implode(' ', $code[1]), $words);

    return array_fill_keys($words[0], true);
}

/**
 * @param array<string, true> $promised
 */
function markInternal(string $source, array $promised): ?string
{
    $tokens = PhpToken::tokenize($source);
    $namespace = '';
    foreach ($tokens as $i => $token) {
        if ($token->is(T_NAMESPACE)) {
            $namespace = nextSignificant($tokens, $i)?->text ?? '';
        }
        if (!$token->is([T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM])) {
            continue;
        }
        $name = nextSignificant($tokens, $i);
        $before = previousSignificant($tokens, $i);
        if (!$name?->is(T_STRING) || $before?->is([T_DOUBLE_COLON, T_NEW])) {
            continue;
        }
        // The page names the 19 kit blocks as a set, not one by one.
        if (isset($promised[$name->text]) || str_starts_with($namespace, 'ContentBlocks\\Kit\\Block')) {
            return null;
        }

        return insertInternal($tokens, declarationStart($tokens, $i));
    }

    return null;
}

/**
 * Index of the first token of the declaration: attributes and modifiers.
 *
 * @param list<PhpToken> $tokens
 */
function declarationStart(array $tokens, int $i): int
{
    $start = $i;
    for ($j = $i - 1; $j >= 0; --$j) {
        $t = $tokens[$j];
        if ($t->is([T_WHITESPACE, T_COMMENT])) {
            continue;
        }
        if ($t->is([T_FINAL, T_ABSTRACT, T_READONLY])) {
            $start = $j;
            continue;
        }
        if (']' === $t->text) {
            for ($depth = 0; $j >= 0; --$j) {
                $depth += match (true) {
                    ']' === $tokens[$j]->text => 1,
                    '[' === $tokens[$j]->text, $tokens[$j]->is(T_ATTRIBUTE) => -1,
                    default => 0,
                };
                if (0 === $depth) {
                    break;
                }
            }
            $start = $j;
            continue;
        }
        break;
    }

    return $start;
}

/**
 * @param list<PhpToken> $tokens
 */
function insertInternal(array $tokens, int $start): ?string
{
    $doc = previousSignificant($tokens, $start, [T_WHITESPACE]);
    if ($doc?->is(T_DOC_COMMENT)) {
        if (preg_match('/\s@internal\s/', $doc->text)) {
            return null;
        }
        $doc->text = preg_replace('~\s*\*/$~', "\n * @internal\n */", $doc->text);
    } else {
        $tokens[$start]->text = "/** @internal */\n".$tokens[$start]->text;
    }

    return implode('', array_map(static fn (PhpToken $t): string => $t->text, $tokens));
}

/**
 * @param list<PhpToken> $tokens
 */
function nextSignificant(array $tokens, int $i): ?PhpToken
{
    for ($j = $i + 1; isset($tokens[$j]); ++$j) {
        if (!$tokens[$j]->isIgnorable()) {
            return $tokens[$j];
        }
    }

    return null;
}

/**
 * @param list<PhpToken> $tokens
 * @param list<int>      $skip
 */
function previousSignificant(array $tokens, int $i, array $skip = [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT]): ?PhpToken
{
    for ($j = $i - 1; $j >= 0; --$j) {
        if (!$tokens[$j]->is($skip)) {
            return $tokens[$j];
        }
    }

    return null;
}
