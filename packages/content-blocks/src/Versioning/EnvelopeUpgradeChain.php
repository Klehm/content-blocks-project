<?php

declare(strict_types=1);

namespace ContentBlocks\Versioning;

/**
 * Walks a stored payload from the envelope format it declares to the one the
 * code reads today, one {@see EnvelopeUpgraderInterface} at a time.
 *
 * **The chain ships empty.** Only one envelope format of each kind exists so
 * far, so there is nothing to walk and every call is a no-op. That is the point:
 * the mechanism has to exist *before* the first bump, because the alternative —
 * refusing every payload written under the old format — is what makes a format
 * bump unthinkable in the first place. The day a step is added, old templates
 * and old export files keep working with no further plumbing.
 *
 * Steps form a linear path (v1 → v2 → v3): each declares one source and one
 * target, so the walk is a lookup by source format, not a graph search. A cycle
 * or a missing link simply means "no path", which callers turn into their own
 * refusal — {@see \ContentBlocks\SectionTemplate\UnsupportedTemplateFormatException}
 * for a template, an `InvalidArgumentException` for an import.
 */
final class EnvelopeUpgradeChain
{
    /** Guards against a cycle in host-supplied steps turning into an endless walk. */
    private const MAX_STEPS = 20;

    /** @var array<string, EnvelopeUpgraderInterface> keyed by source format */
    private array $bySource = [];

    /**
     * @param iterable<EnvelopeUpgraderInterface> $upgraders
     */
    public function __construct(iterable $upgraders = [])
    {
        foreach ($upgraders as $upgrader) {
            // Last one registered for a source wins, so a host can override a
            // shipped step the way it would any other service.
            $this->bySource[$upgrader->upgradesFrom()] = $upgrader;
        }
    }

    /**
     * Cheap predicate: is $from readable as $to? Called per row when listing the
     * section-template library, so the picker can rule a payload out before an
     * editor clicks it.
     */
    public function supports(string $from, string $to): bool
    {
        return $this->pathFrom($from, $to) !== null;
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     *
     * @throws \LogicException when no path exists — callers are expected to have
     *                         asked {@see supports()} first and to raise their own
     *                         domain error instead
     */
    public function upgrade(array $payload, string $from, string $to): array
    {
        $path = $this->pathFrom($from, $to);
        if ($path === null) {
            throw new \LogicException(sprintf('No envelope upgrade path from "%s" to "%s".', $from, $to));
        }

        foreach ($path as $step) {
            $payload = $step->upgrade($payload);
        }

        // The walk is what makes the format current; say so explicitly rather
        // than trusting every step to have rewritten the key.
        $payload['format'] = $to;

        return $payload;
    }

    /**
     * @return list<EnvelopeUpgraderInterface>|null null when $to is unreachable
     */
    private function pathFrom(string $from, string $to): ?array
    {
        if ($from === $to) {
            return [];
        }

        $path = [];
        $current = $from;
        $seen = [$from => true];

        while (isset($this->bySource[$current])) {
            $step = $this->bySource[$current];
            $path[] = $step;
            $current = $step->upgradesTo();

            if ($current === $to) {
                return $path;
            }
            if (isset($seen[$current]) || \count($path) >= self::MAX_STEPS) {
                return null;
            }
            $seen[$current] = true;
        }

        return null;
    }
}
