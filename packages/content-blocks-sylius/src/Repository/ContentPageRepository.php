<?php

declare(strict_types=1);

namespace ContentBlocks\Sylius\Repository;

use ContentBlocks\Entity\ContentArea;
use ContentBlocks\Sylius\Entity\ContentPage;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Sylius\Component\Channel\Model\ChannelInterface;

/**
 * The plugin's queries on pages; the admin CRUD keeps Sylius's repository.
 *
 * @extends ServiceEntityRepository<ContentPage>
 */
class ContentPageRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ContentPage::class);
    }

    /** Enabled or not: the caller decides who may see a disabled page. */
    public function findOneByChannelAndSlug(ChannelInterface $channel, string $locale, string $slug): ?ContentPage
    {
        $page = $this->createQueryBuilder('o')
            ->innerJoin('o.translations', 'translation', 'WITH', 'translation.locale = :locale')
            ->innerJoin('o.channels', 'channel', 'WITH', 'channel = :channel')
            ->andWhere('translation.slug = :slug')
            ->setParameter('locale', $locale)
            ->setParameter('channel', $channel)
            ->setParameter('slug', $slug)
            ->getQuery()
            ->getOneOrNullResult();

        return $page instanceof ContentPage ? $page : null;
    }

    public function findOneByContentArea(ContentArea $area): ?ContentPage
    {
        return $this->findOneBy(['contentArea' => $area]);
    }
}
