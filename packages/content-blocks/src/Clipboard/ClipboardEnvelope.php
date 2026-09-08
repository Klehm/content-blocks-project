<?php

declare(strict_types=1);

namespace ContentBlocks\Clipboard;

/**
 * The wrapper a copy produces and a paste reads back — the outermost sanity
 * check only; what is inside is still untrusted.
 *
 * @see docs/internals/clipboard.md#the-envelope
 */
final class ClipboardEnvelope
{
    public const FORMAT = 'content-blocks/clipboard-v1';

    public const SCOPE_SECTION = 'section';
    public const SCOPE_BLOCK = 'block';

    /**
     * @param self::SCOPE_*        $scope
     * @param array<string, mixed> $payload the scope's snapshot, own `format`
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
     * @throws UnreadableClipboardException when this build cannot read it
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
     * @throws IncompatibleClipboardVersionException when copied under another
     *                                               content generation
     */
    public function assertContentVersion(int $current): void
    {
        // NULL means "copied before the stamp existed" and names no generation,
        // so it is refused like any other mismatch. Copying again is cheap.
        if ($this->contentVersion !== $current) {
            throw new IncompatibleClipboardVersionException($this->contentVersion, $current);
        }
    }
}
