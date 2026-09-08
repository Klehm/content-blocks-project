<?php

declare(strict_types=1);

namespace ContentBlocks\Asset;

/**
 * The one definition of "this string references a file", shared by the
 * exporter and the garbage collector so the two cannot drift apart.
 *
 * @see docs/internals/assets.md#one-definition-of-a-reference
 */
final class AssetReferenceCollector
{
    /**
     * Characters that end a URL in `src`, `srcset`, `url(…)` or prose.
     * Everything between two of them is one candidate.
     */
    private const DELIMITERS = " \t\n\r\0\x0B\"'<>()[]{},;`\\|";

    /** Trailing prose punctuation to retry without — `see /uploads/a.png.` */
    private const TRAILING_PUNCTUATION = '.,;:!?';

    public function __construct(
        private readonly AssetResolverInterface $assetResolver,
    ) {
    }

    /**
     * Every distinct asset path referenced anywhere in $value, in first-seen
     * order. Ambiguous candidates contribute every plausible reading.
     *
     * @return list<string>
     */
    public function collect(mixed $value): array
    {
        /** @var array<string, true> $found */
        $found = [];
        $this->walk($value, null, $found);

        return array_keys($found);
    }

    /**
     * Hands every asset reference to $replace and substitutes what it returns,
     * in place inside markup or as the whole value.
     *
     * @param callable(string):string $replace
     */
    public function map(mixed $value, callable $replace): mixed
    {
        $ignored = [];

        return $this->walk($value, $replace, $ignored);
    }

    /**
     * The single traversal behind both entry points — two walks would be two
     * definitions of "a reference".
     *
     * @param (callable(string):string)|null $replace
     * @param array<string, true>            $found
     */
    private function walk(mixed $value, ?callable $replace, array &$found): mixed
    {
        if (\is_array($value)) {
            $out = [];
            foreach ($value as $key => $item) {
                $out[$key] = $this->walk($item, $replace, $found);
            }

            return $out;
        }

        if (!\is_string($value) || $value === '') {
            return $value;
        }

        // Shape 1 first, so a resolver accepting a slash-less value is still
        // honored — the scan below requires a separator.
        if ($this->assetResolver->isAssetPath($value)) {
            $found[$value] = true;

            return $replace === null ? $value : $replace($value);
        }

        return $this->walkString($value, $replace, $found);
    }

    /**
     * @param (callable(string):string)|null $replace
     * @param array<string, true>            $found
     */
    private function walkString(string $value, ?callable $replace, array &$found): string
    {
        // Every stored path holds a separator, so a string without one cannot
        // embed a reference — this keeps ordinary text off the scan.
        if (!str_contains($value, '/')) {
            return $value;
        }

        /**
         * @var array<string, array{path: string, marks: list<string>}> $matches
         */
        $matches = [];

        foreach (explode("\n", strtr($value, self::delimiterMap())) as $candidate) {
            if ($candidate === '' || !str_contains($candidate, '/')) {
                continue;
            }

            $match = $this->matchIn($candidate);
            if ($match !== null) {
                $matches[$match['text']] = ['path' => $match['path'], 'marks' => $match['marks']];
            }
        }

        if ($matches === []) {
            return $value;
        }

        foreach ($matches as $match) {
            foreach ($match['marks'] as $path) {
                $found[$path] = true;
            }
        }

        if ($replace === null) {
            return $value;
        }

        // Longest first: `/uploads/a.png` is a prefix of `/uploads/a.png.webp`,
        // and replacing the short one first would corrupt the long one.
        $tokens = array_keys($matches);
        usort($tokens, static fn (string $a, string $b) => \strlen($b) <=> \strlen($a));

        $search = [];
        $replacements = [];
        foreach ($tokens as $token) {
            $search[] = $token;
            $replacements[] = $replace($matches[$token]['path']);
        }

        return str_replace($search, $replacements, $value);
    }

    /**
     * What a candidate names: `text` the literal substring to substitute,
     * `path` what it resolves to, `marks` every path it plausibly names.
     *
     * @see docs/internals/assets.md#one-definition-of-a-reference
     *
     * @return array{text: string, path: string, marks: list<string>}|null
     */
    private function matchIn(string $candidate): ?array
    {
        $trimmed = rtrim($candidate, self::TRAILING_PUNCTUATION);
        $trimmedIsDistinct = $trimmed !== $candidate && $trimmed !== '';

        // 1. As written: every absolute path, and every absolute URL when the
        //    resolver is keyed on a CDN host.
        if ($this->assetResolver->isAssetPath($candidate)) {
            // A trailing period is far likelier to be punctuation than part of
            // a filename, so the trimmed reading wins — but both are marked.
            if ($trimmedIsDistinct && $this->assetResolver->isAssetPath($trimmed)) {
                return ['text' => $trimmed, 'path' => $trimmed, 'marks' => [$candidate, $trimmed]];
            }

            return ['text' => $candidate, 'path' => $candidate, 'marks' => [$candidate]];
        }

        // 2. Minus trailing punctuation, for a resolver strict enough to have
        //    rejected the over-long spelling at step 1.
        if ($trimmedIsDistinct && $this->assetResolver->isAssetPath($trimmed)) {
            return ['text' => $trimmed, 'path' => $trimmed, 'marks' => [$trimmed]];
        }

        // 3. Made absolute, for relative candidates only. The whole candidate
        //    is the text to substitute, `../../uploads/a.jpg` included.
        $absolute = $this->absolutize($candidate);

        return $absolute === null
            ? null
            : ['text' => $candidate, 'path' => $absolute, 'marks' => [$absolute]];
    }

    private function absolutize(string $candidate): ?string
    {
        if (str_starts_with($candidate, '/') || str_contains($candidate, '://')) {
            return null;
        }

        $rest = $candidate;
        while (str_starts_with($rest, './') || str_starts_with($rest, '../')) {
            $rest = substr($rest, str_starts_with($rest, './') ? 2 : 3);
        }

        if ($rest === '') {
            return null;
        }

        $absolute = '/' . $rest;

        return $this->assetResolver->isAssetPath($absolute) ? $absolute : null;
    }

    /**
     * Delimiters → newline, so one `explode()` splits. Built lazily because a
     * const expression cannot loop.
     *
     * @return array<string, string>
     */
    private static function delimiterMap(): array
    {
        static $map = null;

        if ($map === null) {
            $map = [];
            foreach (str_split(self::DELIMITERS) as $char) {
                $map[$char] = "\n";
            }
        }

        return $map;
    }
}
