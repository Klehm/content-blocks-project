<?php

declare(strict_types=1);

namespace ContentBlocks\History;

use ContentBlocks\Entity\ActionLogEntry;
use ContentBlocks\Entity\ContentArea;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The shipped {@see ActionLogStoreInterface}: one table, indexed on the stack
 * key.
 *
 * @see docs/internals/history.md#where-the-journal-lives
 *
 * @internal
 */
final class DoctrineActionLogStore implements ActionLogStoreInterface
{
    private const ENTITY = ActionLogEntry::class;

    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function top(ContentArea $area, string $sessionId): ?ActionLogEntry
    {
        return $this->one($area, $sessionId, undone: false, direction: 'DESC');
    }

    public function firstUndone(ContentArea $area, string $sessionId): ?ActionLogEntry
    {
        return $this->one($area, $sessionId, undone: true, direction: 'ASC');
    }

    public function add(ActionLogEntry $entry): void
    {
        $this->em->persist($entry);
    }

    public function save(): void
    {
        $this->em->flush();
    }

    public function dropUndone(ContentArea $area, string $sessionId): void
    {
        $this->em->createQuery(
            'DELETE FROM ' . self::ENTITY . ' e
             WHERE e.contentArea = :area AND e.sessionId = :session AND e.undone = true'
        )
            ->setParameter('area', $area)
            ->setParameter('session', $sessionId)
            ->execute();
    }

    public function clear(ContentArea $area): void
    {
        $this->em->createQuery('DELETE FROM ' . self::ENTITY . ' e WHERE e.contentArea = :area')
            ->setParameter('area', $area)
            ->execute();
    }

    /**
     * Two ceilings, because two things run away: one editor's stack, and the
     * rows of sessions that never published.
     */
    public function prune(ContentArea $area, string $sessionId, int $keep, \DateTimeImmutable $before): void
    {
        $newest = $this->top($area, $sessionId);
        if ($newest !== null) {
            $this->em->createQuery(
                'DELETE FROM ' . self::ENTITY . ' e
                 WHERE e.contentArea = :area AND e.sessionId = :session AND e.seq <= :cutoff'
            )
                ->setParameter('area', $area)
                ->setParameter('session', $sessionId)
                ->setParameter('cutoff', $newest->getSeq() - $keep)
                ->execute();
        }

        $this->em->createQuery(
            'DELETE FROM ' . self::ENTITY . ' e WHERE e.contentArea = :area AND e.updatedAt < :before'
        )
            ->setParameter('area', $area)
            ->setParameter('before', $before)
            ->execute();
    }

    private function one(ContentArea $area, string $sessionId, bool $undone, string $direction): ?ActionLogEntry
    {
        $result = $this->em->createQuery(
            'SELECT e FROM ' . self::ENTITY . ' e
             WHERE e.contentArea = :area AND e.sessionId = :session AND e.undone = :undone
             ORDER BY e.seq ' . $direction
        )
            ->setParameter('area', $area)
            ->setParameter('session', $sessionId)
            ->setParameter('undone', $undone)
            ->setMaxResults(1)
            ->getOneOrNullResult();

        return $result instanceof ActionLogEntry ? $result : null;
    }
}
