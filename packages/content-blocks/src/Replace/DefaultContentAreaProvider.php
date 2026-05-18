<?php

declare(strict_types=1);

namespace ContentBlocks\Replace;

use ContentBlocks\Entity\ContentArea;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;

/**
 * Default provider — usable without host configuration.
 *
 * Filters by the area id (numeric prefix match) and labels rows as
 * "#<id> — <updatedAt|created>". Hosts override this service to filter
 * by user-meaningful fields (title, slug…) and produce richer labels.
 */
final class DefaultContentAreaProvider implements ContentAreaProviderInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function createQueryBuilder(?string $filter): QueryBuilder
    {
        $qb = $this->em->createQueryBuilder()
            ->select('a')
            ->from(ContentArea::class, 'a');

        $filter = $filter === null ? '' : trim($filter);
        if ($filter !== '' && ctype_digit($filter)) {
            // Default impl only knows about the id; a numeric input is the
            // only thing it can match portably. Text search lives in host
            // implementations that can join through the owning entity
            // (Page, Product…).
            $qb->andWhere('a.id = :id')->setParameter('id', (int) $filter);
        }

        return $qb;
    }

    public function getLabel(ContentArea $area): string
    {
        $stamp = $area->getUpdatedAt();
        $suffix = $stamp instanceof \DateTimeImmutable
            ? $stamp->format('Y-m-d H:i')
            : '—';

        return \sprintf('#%d — %s', (int) $area->getId(), $suffix);
    }
}
