<?php

declare(strict_types=1);

namespace App\Preview;

use App\Entity\Page;
use ContentBlocks\Entity\ContentArea;
use ContentBlocks\Preview\ContentAreaUrlResolverInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class PageContentAreaUrlResolver implements ContentAreaUrlResolverInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UrlGeneratorInterface $urls,
    ) {
    }

    public function resolve(ContentArea $area): string
    {
        $page = $this->em->getRepository(Page::class)->findOneBy(['contentArea' => $area]);

        if (!$page) {
            throw new \RuntimeException(sprintf('No Page references ContentArea #%d', $area->getId()));
        }

        return $this->urls->generate('app_page_show', ['id' => $page->getId()]);
    }
}
