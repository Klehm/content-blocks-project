<?php

declare(strict_types=1);

namespace ContentBlocks\I18n\Machine;

/**
 * One piece of text for a provider. It carries `format` and `label` because
 * both are knowable here and unknowable from the string alone.
 *
 * @see docs/internals/i18n.md#machine-translation-is-a-seam
 */
final class TranslationRequest
{
    public const FORMAT_TEXT = 'text';
    public const FORMAT_HTML = 'html';

    public function __construct(
        /** Echoed back — the only thing tying a result to a field. */
        public readonly string $path,
        public readonly string $text,
        public readonly string $format = self::FORMAT_TEXT,
        /** Field label, already in the editor's language. */
        public readonly ?string $label = null,
        /** Block type id, further context for engines that take it. */
        public readonly ?string $blockType = null,
    ) {
    }

    public function isHtml(): bool
    {
        return $this->format === self::FORMAT_HTML;
    }

    /** The same request with other text, for batching by format. */
    public function withText(string $text): self
    {
        return new self($this->path, $text, $this->format, $this->label, $this->blockType);
    }
}
