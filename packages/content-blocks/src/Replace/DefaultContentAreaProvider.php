<?php

declare(strict_types=1);

namespace ContentBlocks\Replace;

use ContentBlocks\Entity\ContentArea;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;

/**
 * Default provider, usable without host configuration: filters by area id and
 * labels rows "#<id> — <updatedAt>".
 *
 * @see docs/guide/host-services.md
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
            // A numeric input is the only thing this can match portably; text
            // search needs a join through the host's own owning entity.
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
