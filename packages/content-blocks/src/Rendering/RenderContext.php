<?php

declare(strict_types=1);

namespace ContentBlocks\Rendering;

/**
 * Everything the render pipeline needs to know beyond the entity itself.
 *
 * It exists so the pipeline can grow inputs without another signature change:
 * the render methods used to take a bare `?RenderMode`, and adding a parameter
 * to a published interface is a breaking change for every implementor. Locale
 * is the first passenger — content translation lives in a satellite package,
 * but the seam it plugs into has to be frozen with the 1.0 contract.
 *
 * Both properties are optional, and null means "decide for me":
 *
 *  - `$mode` null → {@see BlockRendererInterface::resolveMode()} inspects the
 *    request (only for a full area render; the single-block and single-section
 *    entry points default to PREVIEW, since only the builder calls them).
 *  - `$locale` null → whatever the host's locale-aware resolver considers
 *    current, typically the request locale. Set it to render an area in a
 *    specific language regardless of the request — a language switcher, a
 *    sitemap job, a transactional email.
 *
 * Inside a {@see BlockDataResolverInterface}, `$mode` is always resolved: the
 * renderer materializes it before running the pipeline.
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

    /** Render draft content (the builder's preview), optionally in a given locale. */
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
