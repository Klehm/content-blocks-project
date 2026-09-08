<?php

declare(strict_types=1);

namespace ContentBlocks\BlockType;

/**
 * What a block looks like, reduced to the least a thumbnail needs. Six kinds,
 * a line of text, an image path — resist growing it any further.
 *
 * @see docs/internals/blocks.md#preview-hints-and-why-they-stay-tiny
 */
final class BlockPreviewHint
{
    /** A picture: `image` carries a storage path the admin can load. */
    public const KIND_IMAGE = 'image';
    /** A title line — rendered emphasised, on one line. */
    public const KIND_HEADING = 'heading';
    /** Running copy — rendered small and clamped to a couple of lines. */
    public const KIND_TEXT = 'text';
    /** A call to action — rendered as a pill. */
    public const KIND_BUTTON = 'button';
    /** A horizontal rule; carries no text. */
    public const KIND_RULE = 'rule';
    /** Nothing worth drawing: the tile falls back to the block-type label. */
    public const KIND_GENERIC = 'generic';

    /**
     * Capped rather than rejected: a block cannot know a tile shows two lines.
     *
     * @see docs/internals/blocks.md#preview-hints-and-why-they-stay-tiny
     */
    private const MAX_TEXT = 120;

    private function __construct(
        public readonly string $kind,
        public readonly ?string $text = null,
        public readonly ?string $image = null,
    ) {
    }

    /**
     * @param string $src storage path or absolute URL of the picture; an empty
     *                    value degrades to {@see generic()} rather than
     *                    rendering a broken tile
     */
    public static function image(string $src, ?string $caption = null): self
    {
        $src = trim($src);
        if ($src === '') {
            return self::generic($caption);
        }

        return new self(self::KIND_IMAGE, self::clean($caption), $src);
    }

    public static function heading(?string $text): self
    {
        $text = self::clean($text);

        return $text === null ? self::generic() : new self(self::KIND_HEADING, $text);
    }

    public static function text(?string $text): self
    {
        $text = self::clean($text);

        return $text === null ? self::generic() : new self(self::KIND_TEXT, $text);
    }

    public static function button(?string $label): self
    {
        return new self(self::KIND_BUTTON, self::clean($label));
    }

    public static function rule(): self
    {
        return new self(self::KIND_RULE);
    }

    /**
     * The honest fallback: the tile shows the block-type label instead of
     * pretending to preview content. Also what an empty block degrades to.
     */
    public static function generic(?string $text = null): self
    {
        return new self(self::KIND_GENERIC, self::clean($text));
    }

    /**
     * Collapses whitespace (stored copy carries newlines a one-line tile
     * cannot show), truncates, and normalises "nothing to say" to null.
     */
    private static function clean(?string $text): ?string
    {
        if ($text === null) {
            return null;
        }

        $text = trim((string) preg_replace('/\s+/u', ' ', $text));
        if ($text === '') {
            return null;
        }

        if (mb_strlen($text) > self::MAX_TEXT) {
            $text = rtrim(mb_substr($text, 0, self::MAX_TEXT)) . '…';
        }

        return $text;
    }
}
