<?php

declare(strict_types=1);

namespace ContentBlocks\BlockType;

/**
 * What a block looks like, reduced to the least a thumbnail needs to know.
 *
 * The core owns no opinion about the shape of a block's `data` — only the
 * block type does — so a block that wants to show up meaningfully in the
 * section library says so here, in terms the poster renderer understands
 * without knowing anything about the block ({@see BlockPreviewHintInterface}).
 *
 * Deliberately tiny: six kinds, an optional line of text, an optional image
 * path. It describes a *tile in a thumbnail*, not the block — resist growing
 * it into a second rendering pipeline. Anything a hint cannot express is a
 * sign the poster should stay generic and let the real preview do its job.
 */
final class BlockPreviewHint
{
    /** A picture: `image` carries a storage path the admin can load directly. */
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
     * Text is capped rather than rejected: a tile shows a line or two, and a
     * block has no way of knowing that. Cutting here keeps every caller
     * honest and the list payload small (10 templates × their blocks).
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
