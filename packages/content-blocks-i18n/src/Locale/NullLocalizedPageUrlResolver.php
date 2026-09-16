<?php

declare(strict_types=1);

namespace ContentBlocks\I18n\Locale;

use ContentBlocks\Entity\ContentArea;

/**
 * Default: no URL for any language, so the workbench renders no links until
 * the host says where its pages live.
 */
final class NullLocalizedPageUrlResolver implements LocalizedPageUrlResolverInterface
{
    public function resolve(ContentArea $area, string $locale): ?string
    {
        return null;
    }
}
