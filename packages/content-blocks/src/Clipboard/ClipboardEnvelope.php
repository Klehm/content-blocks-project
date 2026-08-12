<?php

declare(strict_types=1);

namespace ContentBlocks\Clipboard;

/**
 * The wrapper a copy produces and a paste reads back.
 *
 * The clipboard lives in the browser's `localStorage`, which is what makes it
 * useful (copy on one page, paste on another) and what makes it **input**: the
 * payload is user-writable, so nothing in it is trusted. The envelope is only
 * the outermost sanity check — is this ours, which scope, which content
 * generation. What is inside still goes through the instantiator and each
 * block's own form on the way in (see {@see BlockDataReplayer}).
 *
 * `contentVersion` stamps the host's content generation at copy time. Unlike a
 * section template — a stored row, worth migrating forward through
 * {@see \ContentBlocks\Versioning\ContentVersionUpgraderInterface} — a
 * clipboard entry is a few minutes old at most, so a mismatch is refused
 * outright rather than upgraded: the editor copies again under the current
 * generation and loses nothing.
 */
final class ClipboardEnvelope
{
    public const FORMAT = 'content-blocks/clipboard-v1';

    public const SCOPE_SECTION = 'section';
    public const SCOPE_BLOCK = 'block';

    /**
     * @param self::SCOPE_*        $scope
     * @param array<string, mixed> $payload the scope's own snapshot, with its own `format`
     */
    public function __construct(
        public readonly string $scope,
        public readonly array $payload,
        public readonly ?int $contentVersion,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'format' => self::FORMAT,
            'scope' => $this->scope,
            'contentVersion' => $this->contentVersion,
            'payload' => $this->payload,
        ];
    }

    /**
     * @param array<string, mixed> $raw
     *
     * @throws UnreadableClipboardException when this is not a clipboard entry this build can read
     */
    public static function fromArray(array $raw): self
    {
        if (($raw['format'] ?? null) !== self::FORMAT) {
            throw new UnreadableClipboardException('format');
        }

        $scope = $raw['scope'] ?? null;
        if ($scope !== self::SCOPE_SECTION && $scope !== self::SCOPE_BLOCK) {
            throw new UnreadableClipboardException('scope');
        }

        $payload = $raw['payload'] ?? null;
        if (!is_array($payload)) {
            throw new UnreadableClipboardException('payload');
        }

        $version = $raw['contentVersion'] ?? null;

        return new self($scope, $payload, is_int($version) ? $version : null);
    }

    /**
     * @throws IncompatibleClipboardVersionException when the entry was copied under another content generation
     */
    public function assertContentVersion(int $current): void
    {
        // NULL means "copied before the stamp existed" — same reading as an
        // area's, and just as unhelpful here: we cannot tell which generation
        // produced it, so it is refused like any other mismatch. A clipboard
        // entry is cheap to recreate.
        if ($this->contentVersion !== $current) {
            throw new IncompatibleClipboardVersionException($this->contentVersion, $current);
        }
    }
}
