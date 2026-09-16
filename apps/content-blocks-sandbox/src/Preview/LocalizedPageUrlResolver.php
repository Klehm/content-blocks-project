<?php

declare(strict_types=1);

namespace App\Preview;

use App\Entity\Page;
use ContentBlocks\Entity\ContentArea;
use ContentBlocks\I18n\Locale\LocalizedPageUrlResolverInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * The page in each language, for the workbench's links: the French source on
 * `/page/{id}`, a target on `/{_locale}/page/{id}`.
 */
#[AsAlias(LocalizedPageUrlResolverInterface::class)]
final class LocalizedPageUrlResolver implements LocalizedPageUrlResolverInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UrlGeneratorInterface $urls,
    ) {
    }

    public function resolve(ContentArea $area, string $locale): ?string
    {
        $page = $this->em->getRepository(Page::class)->findOneBy(['contentArea' => $area]);

        if ($page === null) {
            return null;
        }

        return $locale === 'fr'
            ? $this->urls->generate('app_page_show', ['id' => $page->getId()])
            : $this->urls->generate('app_page_show_localized', ['_locale' => $locale, 'id' => $page->getId()]);
    }
}
