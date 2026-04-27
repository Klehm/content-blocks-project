<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Page;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Twig\Environment;

final class PageController
{
    public function __construct(
        private readonly Environment $twig,
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route('/page/{id}', name: 'app_page_show', requirements: ['id' => '\d+'])]
    public function show(int $id): Response
    {
        $page = $this->em->find(Page::class, $id);

        if (!$page) {
            return new Response('Page not found', 404);
        }

        return new Response($this->twig->render('page/show.html.twig', [
            'page' => $page,
        ]));
    }
}
