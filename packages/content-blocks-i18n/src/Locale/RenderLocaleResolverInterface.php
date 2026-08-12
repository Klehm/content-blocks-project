<?php

declare(strict_types=1);

namespace ContentBlocks\I18n\Locale;

use ContentBlocks\Rendering\RenderContext;

/**
 * Decides which locale a render is in when the caller did not pin one.
 *
 * The core deliberately does not answer this: `RenderContext::$locale` is
 * nullable and the docblock says "whatever the host's locale-aware resolver
 * considers current". This is that resolver, and it is an interface because
 * "current locale" is a host decision — a request attribute for most apps, a
 * channel or a customer preference for a shop, a job parameter for a
 * sitemap/newsletter run with no request at all.
 *
 * Returning the source locale (or null) means "render the block's own data",
 * which is the untranslated path and costs nothing.
 *
 * Override seam: alias this interface to your own service.
 */
interface RenderLocaleResolverInterface
{
    /**
     * @return string|null the locale to render in, or null for the source locale
     */
    public function resolve(RenderContext $context): ?string;
}
