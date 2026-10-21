<?php

declare(strict_types=1);

namespace ContentBlocks\Sylius\Repository;

use ContentBlocks\Entity\ContentArea;
use ContentBlocks\Sylius\Entity\ContentSlot;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<ContentSlot> */
class ContentSlotRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ContentSlot::class);
    }

    public function findOneByTypeAndSubject(string $type, string $subject): ?ContentSlot
    {
        return $this->findOneBy(['type' => $type, 'subject' => $subject]);
    }

    public function findOneByContentArea(ContentArea $area): ?ContentSlot
    {
        return $this->findOneBy(['contentArea' => $area]);
    }

    /** @return list<ContentSlot> */
    public function findByType(string $type): array
    {
        return array_values($this->findBy(['type' => $type]));
    }
}
