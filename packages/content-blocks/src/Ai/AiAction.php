<?php

declare(strict_types=1);

namespace ContentBlocks\Ai;

/**
 * The AI actions, each switched on or off on its own under
 * `content_blocks.ai.actions`, and offered only when the provider supports it.
 *
 * @see docs/guide/ai.md#the-actions
 */
final class AiAction
{
    /** Rewrite a text field, same meaning, better words. */
    public const REWRITE = 'rewrite';
    /** Shorten a text field. */
    public const SHORTEN = 'shorten';
    /** Rewrite a text field in another tone. */
    public const TONE = 'tone';
    /** Describe an image for its `alt` text. */
    public const ALT_TEXT = 'alt_text';
    /** New content from a prompt: sections after the selected one. */
    public const ADD = 'add';
    /** One section rewritten from a prompt, in its place. */
    public const EDIT_SECTION = 'edit_section';
    /** The whole page rewritten from a prompt, in place of the draft. */
    public const EDIT_PAGE = 'edit_page';
    /** Write a generated document to a draft (`content-blocks:ai:apply`). */
    public const APPLY = 'apply';

    /** The actions a provider answers; `apply` needs none. */
    public const PROVIDED = [
        self::REWRITE,
        self::SHORTEN,
        self::TONE,
        self::ALT_TEXT,
        self::ADD,
        self::EDIT_SECTION,
        self::EDIT_PAGE,
    ];

    public const ALL = [...self::PROVIDED, self::APPLY];

    /** The actions that rewrite one text field. */
    public const TEXT = [self::REWRITE, self::SHORTEN, self::TONE];

    /** The actions that write sections into the draft. */
    public const CONTENT = [self::ADD, self::EDIT_SECTION, self::EDIT_PAGE];

    /** The content actions that rewrite what is there, handed in `current`. */
    public const EDITS = [self::EDIT_SECTION, self::EDIT_PAGE];

    private function __construct()
    {
    }
}
