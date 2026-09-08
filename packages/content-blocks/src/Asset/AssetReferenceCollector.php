<?php

declare(strict_types=1);

namespace ContentBlocks\Asset;

/**
 * The one place that knows how an asset reference hides inside a stored
 * payload. Both sides of the asset lifecycle read it: the exporter, which
 * swaps every reference for an `asset://` token, and the garbage collector,
 * which needs the exact same set of references to decide what is unreachable.
 *
 * Keeping one traversal is not tidiness — a collector that finds *fewer*
 * references than the exporter means an export loses a file, and a collector
 * that finds fewer references than reality means the sweep deletes a file that
 * is still on a page. The two must not be able to drift.
 *
 * ---- Three shapes of reference, all seen in production data ----
 *
 * 1. **The whole string is the path** — `{"src": "/uploads/content-blocks/a.png"}`,
 *    what an {@see \ContentBlocks\Form\Type\ImageUploadType} field stores.
 * 2. **Embedded in markup** — `<p><img src="/uploads/…"></p>`, what a rich-text
 *    field stores once its editor uploads an image through
 *    `/_content-blocks/upload`. Invisible before this class existed:
 *    {@see AssetResolverInterface::isAssetPath()} tests a whole string, so
 *    `str_starts_with('<p><img…', '/uploads/')` is false and every rich-text
 *    image silently fell out of exports.
 * 3. **Embedded *and* relative** — `<img src="../../uploads/content-blocks/…">`.
 *    Rich-text editors rewrite the absolute URL they are handed into a
 *    document-relative one (TinyMCE's `relative_urls` is on by default), so
 *    this is the *normal* spelling for an editor-uploaded image, not an edge
 *    case. A prefix test says no to it while the file is on a live page.
 *
 * Shapes 2 and 3 are found by cutting the string on characters that cannot
 * occur inside a URL in markup or CSS and asking the resolver about each
 * piece, in three spellings — see {@see self::variantsOf()}. That keeps the
 * class storage-agnostic: a resolver that recognizes
 * `https://cdn.example.com/…` works exactly as well as a local prefix.
 *
 * ---- The asymmetry that drives every judgment call here ----
 *
 * Missing a reference deletes a file that is on a live page. Reporting one too
 * many only spares a file until the next run. So where a candidate is
 * genuinely ambiguous, every plausible reading is reported for marking, and
 * the single most likely one is used for substitution.
 */
final class AssetReferenceCollector
{
    /**
     * Characters that end a URL in `src="…"`, `srcset="… 1x, … 2x"`,
     * `url(…)`, or plain prose. Everything between two of them is one
     * candidate.
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
     * Walks $value and hands every asset reference to $replace, substituting
     * the returned string for it — in place inside markup, or as the whole
     * value when the whole value was the path.
     *
     * @param callable(string):string $replace
     */
    public function map(mixed $value, callable $replace): mixed
    {
        $ignored = [];

        return $this->walk($value, $replace, $ignored);
    }

    /**
     * The single traversal behind both entry points: with a $replace callback
     * it substitutes, without one it only records. Sharing it is the point of
     * the class — two walks would be two definitions of "a reference".
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

        // Shape 1 first: the whole string is the path, as written. Handled
        // separately so a resolver that accepts a slash-less value (the scan
        // below requires a separator to keep ordinary prose cheap) is still
        // honored here.
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
        // Every asset path the storage hands out contains a separator (see
        // FileStorageInterface — a public path, not a bare filename), so a
        // string without one cannot embed a reference. This is what keeps the
        // scan off the hot path for ordinary text.
        if (!str_contains($value, '/')) {
            return $value;
        }

        /** @var array<string, array{path: string, marks: list<string>}> $matches literal text => what it names */
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
     * What a candidate names, or null if it names nothing.
     *
     * `text` is the literal substring to substitute — the URL as it appears in
     * the document, which is not always the same as the path it resolves to.
     * `path` is that resolved path, and `marks` every path the candidate
     * plausibly names (a superset of `path`, see the class docblock on the
     * asymmetry between missing and over-reporting a reference).
     *
     * @return array{text: string, path: string, marks: list<string>}|null
     */
    private function matchIn(string $candidate): ?array
    {
        $trimmed = rtrim($candidate, self::TRAILING_PUNCTUATION);
        $trimmedIsDistinct = $trimmed !== $candidate && $trimmed !== '';

        // 1. As written. Covers every absolute path and, for a resolver keyed
        //    on a CDN host, every absolute URL.
        if ($this->assetResolver->isAssetPath($candidate)) {
            // A prefix-testing resolver says yes to `/uploads/a.png.` as
            // readily as to `/uploads/a.png`. A file whose name ends in a
            // period is close to impossible and a sentence ending in one is
            // routine, so the trimmed reading wins the substitution — which
            // also leaves the punctuation outside it, where it belongs. Both
            // are still marked: sparing one extra file until the next run
            // beats deleting a live one.
            if ($trimmedIsDistinct && $this->assetResolver->isAssetPath($trimmed)) {
                return ['text' => $trimmed, 'path' => $trimmed, 'marks' => [$candidate, $trimmed]];
            }

            return ['text' => $candidate, 'path' => $candidate, 'marks' => [$candidate]];
        }

        // 2. Minus trailing prose punctuation, for a resolver strict enough to
        //    have rejected the over-long spelling at step 1.
        if ($trimmedIsDistinct && $this->assetResolver->isAssetPath($trimmed)) {
            return ['text' => $trimmed, 'path' => $trimmed, 'marks' => [$trimmed]];
        }

        // 3. Made absolute — shape 3 in the class docblock. Restricted to
        //    candidates that really are relative, so an unrelated absolute
        //    link like `/blog/uploads/x.png` can never be mistaken for one.
        //    Here the whole candidate is the text to substitute:
        //    `<img src="../../uploads/a.jpg">` must become
        //    `<img src="asset://…">`, not `../../asset://…`.
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
     * Delimiters → newline, so one `explode()` does the splitting. Built once
     * per call rather than held as a const because a const expression cannot
     * loop.
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
