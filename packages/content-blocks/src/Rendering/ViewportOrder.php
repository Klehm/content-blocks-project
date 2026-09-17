<?php

declare(strict_types=1);

namespace ContentBlocks\Rendering;

/**
 * Per-viewport ranks of sibling sections or blocks, stored under `_order` on
 * each element, turned into the `--cb-order-*` values a group renders.
 *
 * @see docs/internals/rendering.md#order-per-viewport
 */
final class ViewportOrder
{
    public const KEY = '_order';
    public const VIEWPORTS = ['tablet', 'mobile'];

    /**
     * The ranks an element holds, ignoring anything malformed.
     *
     * @param array<string, mixed>|null $holder settings or block data
     *
     * @return array<string, int>
     */
    public static function ranks(?array $holder): array
    {
        $raw = $holder[self::KEY] ?? null;
        if (!\is_array($raw)) {
            return [];
        }

        $ranks = [];
        foreach (self::VIEWPORTS as $viewport) {
            $value = $raw[$viewport] ?? null;
            if (\is_int($value) && $value >= 0) {
                $ranks[$viewport] = $value;
            }
        }

        return $ranks;
    }

    /**
     * $holder with one viewport's rank set, or removed when $rank is null.
     *
     * @param array<string, mixed> $holder
     *
     * @return array<string, mixed>
     */
    public static function withRank(array $holder, string $viewport, ?int $rank): array
    {
        if (!\in_array($viewport, self::VIEWPORTS, true)) {
            return $holder;
        }
        $ranks = self::ranks($holder);
        if ($rank === null) {
            unset($ranks[$viewport]);
        } else {
            $ranks[$viewport] = $rank;
        }

        unset($holder[self::KEY]);
        if ($ranks !== []) {
            $holder[self::KEY] = $ranks;
        }

        return $holder;
    }

    /**
     * @param array<string, mixed> $holder
     *
     * @return array<string, mixed>
     */
    public static function withoutRanks(array $holder): array
    {
        unset($holder[self::KEY]);

        return $holder;
    }

    /**
     * CSS variables per sibling, in the order given (desktop order). A group
     * with no rank at all gets none, so its markup is unchanged.
     *
     * @param list<array<string, int>> $ranks one {@see ranks()} per sibling
     *
     * @return list<array<string, string>>
     */
    public static function variables(array $ranks): array
    {
        $vars = array_fill(0, \count($ranks), []);
        $base = array_keys($ranks);

        foreach (self::VIEWPORTS as $viewport) {
            $own = array_map(static fn (array $r): ?int => $r[$viewport] ?? null, $ranks);
            $sequence = self::sequence($own, $base);
            if ($sequence === null) {
                continue;
            }
            foreach ($sequence as $position => $index) {
                $vars[$index]['--cb-order-' . $viewport[0]] = (string) $position;
            }
            // Mobile builds on the tablet order when there is one.
            $base = $sequence;
        }

        return $vars;
    }

    /**
     * Ranked siblings by rank, then base order; each unranked one right after
     * its predecessor in $base. Null when nothing is ranked.
     *
     * @param array<int, int|null> $ranks keyed by sibling index
     * @param list<int>            $base  sibling indexes in base visual order
     *
     * @return list<int>|null sibling indexes in visual order
     */
    public static function sequence(array $ranks, array $base): ?array
    {
        if (array_filter($ranks, static fn (?int $rank): bool => $rank !== null) === []) {
            return null;
        }

        $baseIndex = array_flip($base);
        $ranked = array_values(array_filter($base, static fn (int $i): bool => ($ranks[$i] ?? null) !== null));
        usort($ranked, static fn (int $a, int $b): int => [$ranks[$a], $baseIndex[$a]] <=> [$ranks[$b], $baseIndex[$b]]);

        $sequence = $ranked;
        foreach ($base as $position => $index) {
            if (($ranks[$index] ?? null) !== null) {
                continue;
            }
            $at = $position === 0 ? 0 : array_search($base[$position - 1], $sequence, true) + 1;
            array_splice($sequence, (int) $at, 0, [$index]);
        }

        return $sequence;
    }

    /** @param array<string, string> $vars */
    public static function styleString(array $vars): string
    {
        $out = '';
        foreach ($vars as $name => $value) {
            $out .= $name . ':' . $value . ';';
        }

        return $out;
    }
}
