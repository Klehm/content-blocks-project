<?php

declare(strict_types=1);

namespace ContentBlocks\Publishing;

use ContentBlocks\Entity\ContentArea;

/**
 * A draft kept beside the area — rows of a bundle's own — that Publish would
 * put live. Autoconfigured; read with the area's own draft state.
 *
 * @see docs/internals/publishing.md#drafts-kept-beside-the-area
 */
interface UnpublishedChangesProviderInterface
{
    public function hasUnpublishedChanges(ContentArea $area): bool;
}
