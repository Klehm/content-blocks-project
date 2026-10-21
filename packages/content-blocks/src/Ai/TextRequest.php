<?php

declare(strict_types=1);

namespace ContentBlocks\Ai;

/**
 * One text field to rewrite, with what the provider needs to keep the result
 * valid: the field's limit, its format, the block around it.
 */
final class TextRequest
{
    /**
     * @param string      $action    one of {@see AiAction::TEXT}
     * @param string|null $tone      for {@see AiAction::TONE}: a configured
     *                               tone, e.g. "friendly"
     * @param string|null $blockType the block holding the field, if known
     * @param string|null $field     its path in the block data, e.g.
     *                               `items.0.title`
     * @param string|null $label     the field's label, in the request locale
     * @param int|null    $maxLength the field's limit; past it, the save fails
     * @param string|null $brief     what the host says about the page
     */
    public function __construct(
        public readonly string $action,
        public readonly string $text,
        public readonly string $locale,
        public readonly bool $html = false,
        public readonly ?string $tone = null,
        public readonly ?string $blockType = null,
        public readonly ?string $field = null,
        public readonly ?string $label = null,
        public readonly ?int $maxLength = null,
        public readonly ?string $brief = null,
    ) {
    }
}
