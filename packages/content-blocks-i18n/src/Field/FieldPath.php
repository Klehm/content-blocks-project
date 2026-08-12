<?php

declare(strict_types=1);

namespace ContentBlocks\I18n\Field;

use ContentBlocks\Block\CollectionItemIds;

/**
 * The address of one translatable value inside a block's `data`.
 *
 * Two spellings, and the difference matters:
 *
 *  - a **pattern**, which is what the core's
 *    {@see \ContentBlocks\Translation\TranslatableFieldsInterface} reports —
 *    `title`, `items[].label`, `rows[].cells[].text`. It describes a *shape*:
 *    the `[]` says "a collection lives here" without saying which entry.
 *  - a **path**, which is what a stored translation is keyed by —
 *    `title`, `items[9f2c1a].label`. Every `[]` has been filled in with the
 *    entry's `_id`.
 *
 * Keying translations by `_id` rather than by position is the whole reason
 * `_id` exists (see {@see CollectionItemIds}): an editor who reorders, deletes
 * or duplicates a card must not find the German title of card 1 attached to
 * card 3. Positions move; ids do not.
 *
 * An entry that carries no `_id` is **skipped**, not guessed at. That happens
 * for content written before the ids shipped; `content-blocks:backfill-collection-ids`
 * is the fix, and skipping means such an entry shows up as "not translatable
 * yet" rather than silently binding a translation to a position.
 *
 * All methods are pure and static — this is a grammar, not a service.
 */
final class FieldPath
{
    /**
     * One segment: a key, optionally followed by a bracket. `[]` marks a
     * collection in a pattern; `[9f2c1a]` names an entry in a path.
     */
    private const SEGMENT = '(?<name>[^.\[\]]++)(?:\[(?<id>[^\[\]]*+)\])?';

    /**
     * Every concrete path this pattern reaches in $data, in document order.
     *
     * The expansion is driven by the *data*, so a collection with three entries
     * yields three paths and an empty one yields none — which is exactly what a
     * progress count wants: fields that do not exist are not untranslated work.
     *
     * A pattern segment whose key is absent from $data yields nothing. That is
     * deliberate: a block whose stored payload predates a field simply has no
     * value to translate there yet.
     *
     * @param array<string, mixed> $data
     *
     * @return list<string>
     */
    public static function expand(string $pattern, array $data): array
    {
        $segments = self::segments($pattern);

        if ($segments === []) {
            return [];
        }

        $out = [];
        self::walk($segments, 0, $data, '', $out);

        return $out;
    }

    /**
     * Value at $path, or null when any step of the walk is missing.
     *
     * Null is also a legitimate stored value, so callers that must tell
     * "absent" from "null" use {@see self::has()} instead.
     *
     * @param array<string, mixed> $data
     */
    public static function read(array $data, string $path): mixed
    {
        $segments = self::segments($path);

        if ($segments === []) {
            return null;
        }

        $node = $data;

        foreach ($segments as $segment) {
            if (!\is_array($node) || !\array_key_exists($segment['name'], $node)) {
                return null;
            }

            $node = $node[$segment['name']];

            if ($segment['id'] === null) {
                continue;
            }

            $entry = self::findEntry($node, $segment['id']);

            if ($entry === null) {
                return null;
            }

            $node = $entry;
        }

        return $node;
    }

    /**
     * Whether $path resolves to a key that exists — including one holding null
     * or `''`. An empty string is a translation ("this heading is blank in
     * German"), so merging keys on truthiness would lose deliberate blanks.
     *
     * @param array<string, mixed> $data
     */
    public static function has(array $data, string $path): bool
    {
        $node = $data;
        $segments = self::segments($path);
        $last = \count($segments) - 1;

        foreach ($segments as $i => $segment) {
            if (!\is_array($node) || !\array_key_exists($segment['name'], $node)) {
                return false;
            }

            if ($i === $last && $segment['id'] === null) {
                return true;
            }

            $node = $node[$segment['name']];

            if ($segment['id'] === null) {
                continue;
            }

            $entry = self::findEntry($node, $segment['id']);

            if ($entry === null) {
                return false;
            }

            $node = $entry;
        }

        return true;
    }

    /**
     * $data with $value written at $path.
     *
     * **Only writes into structure that already exists.** A missing key, or a
     * collection with no entry of that id, leaves $data untouched. The
     * alternative — creating the missing branch — would let a stale translation
     * resurrect a field the source has since dropped, or invent a card that no
     * longer exists.
     *
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    public static function write(array $data, string $path, mixed $value): array
    {
        $segments = self::segments($path);

        if ($segments === []) {
            return $data;
        }

        $written = self::writeInto($data, $segments, 0, $value);

        return \is_array($written) ? $written : $data;
    }

    /**
     * The pattern a concrete path belongs to: `items[9f2c].label` → `items[].label`.
     *
     * This is how a stored translation is checked against the allow-list —
     * the tags say which *shapes* may be translated, the stored key names an
     * instance of one.
     */
    public static function patternOf(string $path): string
    {
        $out = '';

        foreach (self::segments($path) as $i => $segment) {
            $out .= ($i > 0 ? '.' : '') . $segment['name'];

            if ($segment['id'] !== null) {
                $out .= '[]';
            }
        }

        return $out;
    }

    /**
     * @param list<string> $patterns
     */
    public static function matchesAny(string $path, array $patterns): bool
    {
        return \in_array(self::patternOf($path), $patterns, true);
    }

    /**
     * 1-based position of the innermost collection entry $path points into, or
     * null when the path touches no collection.
     *
     * Purely for labelling: "Card 2 · Title" reads better in a translation list
     * than `items[9f2c1a].title`. It is derived at display time rather than
     * stored, precisely because it is the thing that moves when an editor
     * reorders — the stored key stays the id.
     *
     * @param array<string, mixed> $data
     */
    public static function entryIndex(array $data, string $path): ?int
    {
        $node = $data;
        $index = null;

        foreach (self::segments($path) as $segment) {
            if (!\is_array($node) || !\array_key_exists($segment['name'], $node)) {
                return $index;
            }

            $node = $node[$segment['name']];

            if ($segment['id'] === null) {
                continue;
            }

            if (!\is_array($node)) {
                return $index;
            }

            $position = 0;
            $found = null;

            foreach ($node as $entry) {
                if (!\is_array($entry)) {
                    continue;
                }

                ++$position;

                if (($entry[CollectionItemIds::KEY] ?? null) === $segment['id']) {
                    $found = $entry;
                    $index = $position;

                    break;
                }
            }

            if ($found === null) {
                return $index;
            }

            $node = $found;
        }

        return $index;
    }

    /**
     * Splits a pattern or a path into segments.
     *
     * Returns `[]` for anything that is not wholly consumed by the grammar,
     * which makes a malformed key from an untrusted payload a no-op everywhere
     * rather than a partial match somewhere.
     *
     * @return list<array{name: string, id: string|null}>
     */
    public static function segments(string $path): array
    {
        if ($path === '') {
            return [];
        }

        // The `(?=.)` after the separator is what rejects a trailing dot:
        // without it `title.` would parse as `title`, quietly aliasing two
        // spellings of the same key.
        $matched = preg_match_all(
            '/' . self::SEGMENT . '(?:\.(?=.)|$)/A',
            $path,
            $matches,
            \PREG_SET_ORDER | \PREG_OFFSET_CAPTURE,
        );

        if ($matched === false || $matched === 0) {
            return [];
        }

        $segments = [];
        $consumed = 0;

        foreach ($matches as $match) {
            // /A anchors each match to the previous one's end, so a gap means
            // the string stopped being a valid path partway through.
            if ($match[0][1] !== $consumed) {
                return [];
            }

            $consumed = $match[0][1] + \strlen($match[0][0]);

            $segments[] = [
                'name' => $match['name'][0],
                // A segment with no bracket at all has no `id` group; one with
                // `[]` has an empty one. Only the latter is a collection.
                'id' => \array_key_exists('id', $match) && $match['id'][1] !== -1 ? $match['id'][0] : null,
            ];
        }

        return $consumed === \strlen($path) ? $segments : [];
    }

    /**
     * @param list<array{name: string, id: string|null}> $segments
     * @param list<string>                               $out
     */
    private static function walk(array $segments, int $index, mixed $node, string $prefix, array &$out): void
    {
        if (!\is_array($node)) {
            return;
        }

        $segment = $segments[$index];
        $name = $segment['name'];

        if (!\array_key_exists($name, $node)) {
            return;
        }

        $path = $prefix === '' ? $name : $prefix . '.' . $name;
        $value = $node[$name];
        $isLast = $index === \count($segments) - 1;

        // A plain segment: either the leaf we were looking for, or a nested
        // array to descend into.
        if ($segment['id'] === null) {
            if ($isLast) {
                $out[] = $path;

                return;
            }

            self::walk($segments, $index + 1, $value, $path, $out);

            return;
        }

        // A collection segment. `[]` in a pattern fans out over the entries;
        // a pattern never ends on one (the tag is on a field *inside* the
        // entry), so an id-bearing last segment is malformed and yields nothing.
        if (!\is_array($value) || $isLast) {
            return;
        }

        foreach ($value as $entry) {
            if (!\is_array($entry)) {
                continue;
            }

            $id = $entry[CollectionItemIds::KEY] ?? null;

            if (!\is_string($id) || $id === '') {
                continue;
            }

            self::walk($segments, $index + 1, $entry, $path . '[' . $id . ']', $out);
        }
    }

    /**
     * @param list<array{name: string, id: string|null}> $segments
     */
    private static function writeInto(mixed $node, array $segments, int $index, mixed $value): mixed
    {
        if (!\is_array($node)) {
            return null;
        }

        $segment = $segments[$index];
        $name = $segment['name'];

        if (!\array_key_exists($name, $node)) {
            return null;
        }

        $isLast = $index === \count($segments) - 1;

        if ($segment['id'] === null) {
            if ($isLast) {
                $node[$name] = $value;

                return $node;
            }

            $child = self::writeInto($node[$name], $segments, $index + 1, $value);

            if ($child === null) {
                return null;
            }

            $node[$name] = $child;

            return $node;
        }

        if (!\is_array($node[$name]) || $isLast) {
            return null;
        }

        foreach ($node[$name] as $key => $entry) {
            if (!\is_array($entry) || ($entry[CollectionItemIds::KEY] ?? null) !== $segment['id']) {
                continue;
            }

            $child = self::writeInto($entry, $segments, $index + 1, $value);

            if ($child === null) {
                return null;
            }

            $node[$name][$key] = $child;

            return $node;
        }

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function findEntry(mixed $collection, string $id): ?array
    {
        if (!\is_array($collection)) {
            return null;
        }

        foreach ($collection as $entry) {
            if (\is_array($entry) && ($entry[CollectionItemIds::KEY] ?? null) === $id) {
                /** @var array<string, mixed> $entry */
                return $entry;
            }
        }

        return null;
    }
}
