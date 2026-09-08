<?php

declare(strict_types=1);

namespace ContentBlocks\I18n\Locale;

use ContentBlocks\Rendering\RenderContext;

/**
 * Which locale a render is in when the caller pinned none — a host decision,
 * hence a seam. Null means the source locale, the untranslated path.
 *
 * @see docs/internals/i18n.md#config-and-mounting
 */
interface RenderLocaleResolverInterface
{
    /**
     * @return string|null null for the source locale
     */
    public function resolve(RenderContext $context): ?string;
}
