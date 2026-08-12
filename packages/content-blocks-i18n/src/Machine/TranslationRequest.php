<?php

declare(strict_types=1);

namespace ContentBlocks\I18n\Machine;

/**
 * One piece of text handed to a machine-translation provider.
 *
 * It carries more than the string because the two things that most often make
 * machine output unusable are both knowable here and unknowable from the text
 * alone:
 *
 *  - **format** — a rich-text field is HTML. Sent as plain text, the tags come
 *    back translated, escaped or dropped. Every serious engine has a markup mode
 *    and needs to be told.
 *  - **label** — "Home" as a *button label* and "Home" as a *heading* translate
 *    differently in German, and a field's own form label is the cheapest
 *    disambiguating context available. Engines that accept context (LLMs
 *    especially) get measurably better output from it; those that do not simply
 *    ignore it.
 */
final class TranslationRequest
{
    public const FORMAT_TEXT = 'text';
    public const FORMAT_HTML = 'html';

    public function __construct(
        /** Echoed back on the outcome — the only thing tying a result to a field. */
        public readonly string $path,
        public readonly string $text,
        public readonly string $format = self::FORMAT_TEXT,
        /** Human label of the field, already translated into the editor's language. */
        public readonly ?string $label = null,
        /** Block type id (`title`, `card`…), further context for engines that take it. */
        public readonly ?string $blockType = null,
    ) {
    }

    public function isHtml(): bool
    {
        return $this->format === self::FORMAT_HTML;
    }

    /** A request for the same text in a different slot — used when batching by format. */
    public function withText(string $text): self
    {
        return new self($this->path, $text, $this->format, $this->label, $this->blockType);
    }
}
