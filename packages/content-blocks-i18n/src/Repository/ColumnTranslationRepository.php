<?php

declare(strict_types=1);

namespace ContentBlocks\I18n\Repository;

use ContentBlocks\Entity\Column;
use ContentBlocks\Entity\ContentArea;
use ContentBlocks\I18n\Entity\ColumnTranslation;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ColumnTranslation>
 *
 * @internal Queried only by the package.
 */
class ColumnTranslationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ColumnTranslation::class);
    }

    public function findOneFor(Column $column, string $locale): ?ColumnTranslation
    {
        return $this->findOneBy(['column' => $column, 'locale' => $locale]);
    }

    /**
     * @return list<ColumnTranslation>
     */
    public function findForArea(ContentArea $area, ?string $locale = null): array
    {
        $qb = $this->createQueryBuilder('t')
            ->innerJoin('t.column', 'c')
            ->innerJoin('c.section', 's')
            ->andWhere('s.contentArea = :area')
            ->setParameter('area', $area);

        if ($locale !== null) {
            $qb->andWhere('t.locale = :locale')->setParameter('locale', $locale);
        }

        /** @var list<ColumnTranslation> $rows */
        $rows = $qb->getQuery()->getResult();

        return $rows;
    }

    /**
     * @param list<int> $columnIds
     *
     * @return list<ColumnTranslation>
     */
    public function findForColumnIds(array $columnIds): array
    {
        if ($columnIds === []) {
            return [];
        }

        /** @var list<ColumnTranslation> $rows */
        $rows = $this->createQueryBuilder('t')
            ->andWhere('t.column IN (:ids)')
            ->setParameter('ids', $columnIds)
            ->getQuery()
            ->getResult();

        return $rows;
    }
}
