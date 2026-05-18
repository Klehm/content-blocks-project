<?php

declare(strict_types=1);

namespace ContentBlocks\Replace;

use ContentBlocks\Entity\ContentArea;
use Doctrine\ORM\QueryBuilder;

/**
 * Drives the "replace content with an existing area" picker.
 *
 * The host app is the only thing that knows what to search on (page titles,
 * product names, SKUs…) and what to display as a row label. The default
 * implementation works directly on ContentArea (id + updatedAt) so the
 * builder is functional out of the box; hosts override this service to
 * surface user-meaningful results.
 *
 * Contract:
 *  - createQueryBuilder() returns a QueryBuilder selecting ContentArea
 *    entities. The caller (ReplaceController) appends ORDER / LIMIT / OFFSET
 *    for pagination and exclusion of the target area.
 *  - getLabel() returns a display string for a single row. Free-form — the
 *    host may include any context (page title, owner, last edit date).
 *
 * Example host implementation that joins through the host's Page entity:
 *
 *     final class PageContentAreaProvider implements ContentAreaProviderInterface
 *     {
 *         public function __construct(private readonly EntityManagerInterface $em) {}
 *
 *         public function createQueryBuilder(?string $filter): QueryBuilder
 *         {
 *             $qb = $this->em->createQueryBuilder()
 *                 ->select('a')
 *                 ->from(ContentArea::class, 'a')
 *                 ->innerJoin(Page::class, 'p', 'WITH', 'p.contentArea = a');
 *             if ($filter !== null && $filter !== '') {
 *                 $qb->andWhere('p.title LIKE :q')->setParameter('q', '%' . $filter . '%');
 *             }
 *             return $qb;
 *         }
 *
 *         public function getLabel(ContentArea $area): string
 *         {
 *             $page = $this->em->getRepository(Page::class)->findOneBy(['contentArea' => $area]);
 *             return $page?->getTitle() ?? ('#' . $area->getId());
 *         }
 *     }
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
