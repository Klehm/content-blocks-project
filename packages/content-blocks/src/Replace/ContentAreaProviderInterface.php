<?php

declare(strict_types=1);

namespace ContentBlocks\Replace;

use ContentBlocks\Entity\ContentArea;
use Doctrine\ORM\QueryBuilder;

/**
 * Drives the replace-content picker. Only the host knows what to search on and
 * what a row should say; the default works on id and updatedAt alone.
 *
 * @see docs/guide/host-services.md
 */
interface ContentAreaProviderInterface
{
    /**
     * Returns a QueryBuilder selecting ContentArea entities that match the
     * optional filter. A null or empty filter must return all candidates.
     */
    public function createQueryBuilder(?string $filter): QueryBuilder;

    /**
     * Display label for the given area in the picker list.
     */
    public function getLabel(ContentArea $area): string;
}
