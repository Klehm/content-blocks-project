<?php

declare(strict_types=1);

namespace ContentBlocks\I18n\Field;

use ContentBlocks\Block\CollectionItemIds;

/**
 * The address of one translatable value inside a block's `data`. A grammar,
 * not a service: every method is pure and static.
 *
 * @see docs/internals/i18n.md#paths-and-patterns
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
     * Driven by the data, so fields that do not exist yield nothing.
     *
     * @see docs/internals/i18n.md#paths-and-patterns
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
     * Value at $path, or null when a step is missing. Null is also a legitimate
     * value, so telling absent from null needs {@see self::has()}.
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
     * Whether the key exists, including one holding null or `''` — merging on
     * truthiness would lose a deliberate blank.
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
     * **Only writes into structure that already exists**, so a stale
     * translation cannot resurrect a dropped field or invent a card.
     *
     * @see docs/internals/i18n.md#paths-and-patterns
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
     * `items[9f2c].label` to `items[].label` — how a stored translation is
     * checked against the allow-list, which names shapes rather than instances.
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
     * 1-based position of the innermost entry, purely for labelling. Derived at
     * display time because it is the thing reordering moves.
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
     * `[]` for anything the grammar does not wholly consume, so a malformed key
     * is a no-op everywhere rather than a partial match somewhere.
     *
     * @return list<array{name: string, id: string|null}>
     */
    public static function segments(string $path): array
    {
        if ($path === '') {
            return [];
        }

        // `(?=.)` rejects a trailing dot: without it `title.` would parse as
        // `title`, aliasing two spellings of one key.
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

        // A pattern never ends on a collection — the tag sits on a field
        // inside the entry — so an id-bearing last segment is malformed.
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
