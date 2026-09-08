<?php

declare(strict_types=1);

namespace ContentBlocks\Versioning;

/**
 * Walks a payload from the envelope format it declares to today's, one
 * {@see EnvelopeUpgraderInterface} at a time. Ships empty, on purpose.
 *
 * @see docs/internals/versioning.md#the-envelope-chain
 */
final class EnvelopeUpgradeChain
{
    /** Guards against a cycle in host steps becoming an endless walk. */
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
     * Cheap predicate, called per row when listing the library so the picker
     * can rule a payload out before an editor clicks it.
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
     * @throws \LogicException when no path exists; callers ask
     *                         {@see supports()} first and raise their own
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
