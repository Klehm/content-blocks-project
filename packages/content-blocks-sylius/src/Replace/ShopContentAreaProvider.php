<?php

declare(strict_types=1);

namespace ContentBlocks\Sylius\Replace;

use ContentBlocks\Entity\ContentArea;
use ContentBlocks\Replace\ContentAreaProviderInterface;
use ContentBlocks\Sylius\Entity\ContentPage;
use ContentBlocks\Sylius\Entity\ContentSlot;
use ContentBlocks\Sylius\Owner\AreaOwnerLocator;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use Symfony\Contracts\Translation\TranslatorInterface;

/** "Insert content" lists the plugin's pages and slots, searched by name. */
final class ShopContentAreaProvider implements ContentAreaProviderInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AreaOwnerLocator $owners,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function createQueryBuilder(?string $filter): QueryBuilder
    {
        $qb = $this->em->createQueryBuilder()->select('a')->from(ContentArea::class, 'a');
        $page = $this->em->createQueryBuilder()->select('1')->from(ContentPage::class, 'p')
            ->leftJoin('p.translations', 'pt')
            ->andWhere('p.contentArea = a');
        $slot = $this->em->createQueryBuilder()->select('1')->from(ContentSlot::class, 's')
            ->andWhere('s.contentArea = a');

        $filter = trim((string) $filter);
        if ($filter !== '') {
            $page->andWhere('p.code LIKE :filter OR pt.title LIKE :filter');
            $slot->andWhere('s.subject LIKE :filter OR s.type LIKE :filter');
            $qb->setParameter('filter', '%' . addcslashes($filter, '%_') . '%');
        }

        return $qb->andWhere($qb->expr()->orX(
            $qb->expr()->exists($page->getDQL()),
            $qb->expr()->exists($slot->getDQL()),
        ));
    }

    public function getLabel(ContentArea $area): string
    {
        $owner = $this->owners->locate($area);
        $label = match (true) {
            $owner instanceof ContentPage => $this->translator->trans(
                'content_blocks_sylius.ui.area_label.page',
                ['%title%' => $owner->getAnyTitle(), '%code%' => (string) $owner->getCode()],
                'messages',
            ),
            $owner instanceof ContentSlot => $this->translator->trans(
                'content_blocks_sylius.ui.area_label.' . $owner->getType(),
                ['%subject%' => $owner->getSubject()],
                'messages',
            ),
            default => '#' . (string) $area->getId(),
        };
        $stamp = $area->getUpdatedAt();

        return $stamp === null ? $label : $label . ' · ' . $stamp->format('Y-m-d H:i');
    }
}
