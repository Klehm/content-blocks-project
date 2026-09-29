<?php

declare(strict_types=1);

namespace ContentBlocks\I18n\Lifecycle;

use ContentBlocks\Entity\ContentArea;
use ContentBlocks\Publishing\UnpublishedChangesProviderInterface;

/**
 * Lights the builder's Publish button when only a translation changed. Kept
 * apart so the package still boots on a core without the seam.
 */
final class TranslationUnpublishedChanges implements UnpublishedChangesProviderInterface
{
    public function __construct(
        private readonly TranslationDrafts $drafts,
    ) {
    }

    public function hasUnpublishedChanges(ContentArea $area): bool
    {
        return $this->drafts->any($area);
    }
}
