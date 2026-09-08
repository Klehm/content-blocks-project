<?php

declare(strict_types=1);

namespace ContentBlocks\Rendering;

/**
 * What the render pipeline needs beyond the entity. Both properties optional;
 * null means "decide for me".
 *
 * @see docs/internals/rendering.md#why-the-pipeline-takes-a-context-object
 */
final class RenderContext
{
    public function __construct(
        public readonly ?RenderMode $mode = null,
        public readonly ?string $locale = null,
    ) {
    }

    /** Render published content only, optionally in a given locale. */
    public static function forPublic(?string $locale = null): self
    {
        return new self(RenderMode::PUBLIC, $locale);
    }

    /** Render draft content (the preview), optionally in a given locale. */
    public static function forPreview(?string $locale = null): self
    {
        return new self(RenderMode::PREVIEW, $locale);
    }

    /** Let the request decide the mode; pin the locale. */
    public static function forLocale(?string $locale): self
    {
        return new self(null, $locale);
    }

    public function withMode(?RenderMode $mode): self
    {
        return new self($mode, $this->locale);
    }

    public function withLocale(?string $locale): self
    {
        return new self($this->mode, $locale);
    }
}
