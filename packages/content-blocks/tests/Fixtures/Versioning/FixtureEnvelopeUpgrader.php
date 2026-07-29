<?php

declare(strict_types=1);

namespace ContentBlocks\Tests\Fixtures\Versioning;

use ContentBlocks\Versioning\EnvelopeUpgraderInterface;

/**
 * Stands in for the step a future format bump would ship: it reads a made-up
 * `content-blocks/section-v0` and produces today's format, moving the section's
 * columns out of a wrapper key.
 *
 * A named class rather than an anonymous one because autoconfiguration matches
 * on the service class.
 */
final class FixtureEnvelopeUpgrader implements EnvelopeUpgraderInterface
{
    public const LEGACY_FORMAT = 'content-blocks/section-v0';

    public function upgradesFrom(): string
    {
        return self::LEGACY_FORMAT;
    }

    public function upgradesTo(): string
    {
        return 'content-blocks/section-v1';
    }

    public function upgrade(array $payload): array
    {
        // The kind of restructuring an envelope bump is for: the shape around
        // the content moves, the block data inside is carried over untouched.
        if (isset($payload['body']) && \is_array($payload['body'])) {
            $payload['columns'] = $payload['body'];
            unset($payload['body']);
        }

        return $payload;
    }
}
