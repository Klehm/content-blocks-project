<?php

declare(strict_types=1);

namespace ContentBlocks\I18n\Locale;

use ContentBlocks\Entity\ContentArea;

/**
 * The public URL of a page in one language — how a host spells a locale in
 * its routes is its own business. Null means "no link for this language".
 *
 * @see docs/guide/translation.md#links-to-each-language
 */
interface LocalizedPageUrlResolverInterface
{
    /**
     * @param string $locale the source locale or a configured target
     */
    public function resolve(ContentArea $area, string $locale): ?string;
}
