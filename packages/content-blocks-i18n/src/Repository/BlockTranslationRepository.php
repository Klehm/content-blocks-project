<?php

declare(strict_types=1);

namespace ContentBlocks\I18n\Repository;

use ContentBlocks\Entity\Block;
use ContentBlocks\Entity\ContentArea;
use ContentBlocks\I18n\Entity\BlockTranslation;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<BlockTranslation>
 *
 * @internal Queried only by the package. See FREEZE-AUDIT.md.
 */
class BlockTranslationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, BlockTranslation::class);
    }

    public function findOneFor(Block $block, string $locale): ?BlockTranslation
    {
        return $this->findOneBy(['block' => $block, 'locale' => $locale]);
    }

    /**
     * Every translation row of an area, for one locale or all of them.
     *
     * This is the query that keeps rendering a translated page O(1) instead of
     * O(blocks): one join down to the area, loaded once, and the resolver reads
     * from the resulting map. Without it every block on the page would issue its
     * own SELECT — the classic N+1 that a side table invites and that the
     * envelope schema would not have had.
     *
     * @return list<BlockTranslation>
     */
    public function findForArea(ContentArea $area, ?string $locale = null): array
    {
        $qb = $this->createQueryBuilder('t')
            ->innerJoin('t.block', 'b')
            ->innerJoin('b.column', 'c')
            ->innerJoin('c.section', 's')
            ->andWhere('s.contentArea = :area')
            ->setParameter('area', $area);

        if ($locale !== null) {
            $qb->andWhere('t.locale = :locale')->setParameter('locale', $locale);
        }

        /** @var list<BlockTranslation> $rows */
        $rows = $qb->getQuery()->getResult();

        return $rows;
    }

    /**
     * @param list<int> $blockIds
     *
     * @return list<BlockTranslation>
     */
    public function findForBlockIds(array $blockIds, ?string $locale = null): array
    {
        if ($blockIds === []) {
            return [];
        }

        $qb = $this->createQueryBuilder('t')
            ->andWhere('t.block IN (:ids)')
            ->setParameter('ids', $blockIds);

        if ($locale !== null) {
            $qb->andWhere('t.locale = :locale')->setParameter('locale', $locale);
        }

        /** @var list<BlockTranslation> $rows */
        $rows = $qb->getQuery()->getResult();

        return $rows;
    }

    /**
     * Locales that actually carry content for this area — what a language
     * switcher offers, as opposed to what the config allows.
     *
     * @return list<string>
     */
    public function findLocalesForArea(ContentArea $area): array
    {
        /** @var list<array{locale: string}> $rows */
        $rows = $this->createQueryBuilder('t')
            ->select('DISTINCT t.locale AS locale')
            ->innerJoin('t.block', 'b')
            ->innerJoin('b.column', 'c')
            ->innerJoin('c.section', 's')
            ->andWhere('s.contentArea = :area')
            ->setParameter('area', $area)
            ->orderBy('t.locale', 'ASC')
            ->getQuery()
            ->getArrayResult();

        return array_column($rows, 'locale');
    }

    /**
     * Wipes a locale across an area — the "we are dropping German" button.
     * Returns the number of rows removed.
     */
    public function deleteLocaleForArea(ContentArea $area, string $locale): int
    {
        $ids = $this->createQueryBuilder('t')
            ->select('t.id')
            ->innerJoin('t.block', 'b')
            ->innerJoin('b.column', 'c')
            ->innerJoin('c.section', 's')
            ->andWhere('s.contentArea = :area')
            ->andWhere('t.locale = :locale')
            ->setParameter('area', $area)
            ->setParameter('locale', $locale)
            ->getQuery()
            ->getSingleColumnResult();

        if ($ids === []) {
            return 0;
        }

        return (int) $this->createQueryBuilder('t')
            ->delete()
            ->andWhere('t.id IN (:ids)')
            ->setParameter('ids', $ids)
            ->getQuery()
            ->execute();
    }
}
