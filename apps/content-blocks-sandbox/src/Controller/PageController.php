<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Page;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
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

    #[Route('/', name: 'app_page_list')]
    public function list(): Response
    {
        $pages = $this->em->getRepository(Page::class)->findAll();

        return new Response($this->twig->render('page/list.html.twig', [
            'pages' => $pages,
        ]));
    }

    #[Route('/page/create', name: 'app_page_create', methods: ['POST'])]
    public function create(Request $request): Response
    {
        $title = $request->request->getString('title', 'New page');
        $slug = $request->request->getString('slug', '');

        if (!$slug) {
            $slug = Page::slugify($title);
        }

        $page = new Page();
        $page->setTitle($title);
        $page->setSlug($slug);

        $this->em->persist($page);
        $this->em->flush();

        return new Response('', 302, ['Location' => '/admin/page/' . $page->getId()]);
    }

    #[Route('/admin/page/{id}', name: 'app_page_builder', requirements: ['id' => '\d+'])]
    public function builder(int $id): Response
    {
        $page = $this->em->find(Page::class, $id);

        if (!$page) {
            return new Response('Page not found', 404);
        }

        return new Response($this->twig->render('page/builder.html.twig', [
            'page' => $page,
        ]));
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
