<?php

declare(strict_types=1);

namespace App\Preview;

use App\Entity\Model;
use App\Entity\Page;
use ContentBlocks\Entity\ContentArea;
use ContentBlocks\Preview\ContentAreaUrlResolverInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Sylius sandbox resolver: maps a ContentArea back to the URL that renders it.
 * A Page's area resolves to its public `/page/{id}`; a Model's area (reusable
 * layout, no public page) resolves to the `/model/{id}` preview route so the
 * builder iframe can still edit it.
 */
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
        if ($page) {
            return $this->urls->generate('app_page_show', ['id' => $page->getId()]);
        }

        $model = $this->em->getRepository(Model::class)->findOneBy(['contentArea' => $area]);
        if ($model) {
            return $this->urls->generate('app_model_show', ['id' => $model->getId()]);
        }

        throw new \RuntimeException(sprintf('No Page or Model references ContentArea #%d', $area->getId()));
    }
}
