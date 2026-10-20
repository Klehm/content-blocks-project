<?php

declare(strict_types=1);

namespace App\Ai;

use App\Entity\Page;
use ContentBlocks\Ai\AiBriefProviderInterface;
use ContentBlocks\Entity\ContentArea;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The brief every AI request carries: here, the title of the page that owns
 * the area. A shop would hand its product sheet instead.
 */
final class PageBriefProvider implements AiBriefProviderInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function briefFor(ContentArea $area): ?string
    {
        $page = $this->em->getRepository(Page::class)->findOneBy(['contentArea' => $area]);

        return $page instanceof Page ? sprintf('A page titled "%s".', $page->getTitle()) : null;
    }
}
