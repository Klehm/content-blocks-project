<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Page;
use ContentBlocks\Entity\ContentArea;
use ContentBlocks\Entity\Section;
use ContentBlocks\Service\SectionCloner;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Twig\Environment;

final class PageController
{
    public function __construct(
        private readonly Environment $twig,
        private readonly EntityManagerInterface $em,
        private readonly SectionCloner $sectionCloner,
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

    /**
     * Host-side handler for the builder's "Save as model" topbar action.
     *
     * This is the receiving end of the package's generic `cb:builder:action`
     * event: the bundle stays agnostic about what the button does, the host
     * decides. Here we deep-clone the page's ContentArea (via the package's
     * SectionCloner) into a brand-new Page tagged "(model)", so editors can
     * later reuse that layout through the "Insert content" picker.
     */
    #[Route('/admin/page/{id}/save-as-model', name: 'app_page_save_as_model', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function saveAsModel(int $id): Response
    {
        $source = $this->em->find(Page::class, $id);

        if (!$source || !$source->getContentArea()) {
            return new JsonResponse(['error' => 'Page not found'], Response::HTTP_NOT_FOUND);
        }

        $model = new Page();
        $model->setTitle($source->getTitle() . ' (model)');
        $model->setSlug(Page::slugify($source->getTitle()) . '-model-' . bin2hex(random_bytes(3)));

        $area = new ContentArea();
        $model->setContentArea($area);

        // Clone the source's non-deleted sections in previewPosition order —
        // same filtering the replace-with flow uses, so the model mirrors the
        // editor's current draft rather than the last published state.
        $sections = array_values(array_filter(
            $source->getContentArea()->getSections()->toArray(),
            static fn (Section $section) => !$section->isDeleted(),
        ));
        usort(
            $sections,
            static fn (Section $a, Section $b) => $a->getPreviewPosition() <=> $b->getPreviewPosition(),
        );

        foreach ($sections as $i => $section) {
            $copy = $this->sectionCloner->cloneSection($section);
            $copy->setPreviewPosition($i);
            $area->addSection($copy);
            $this->em->persist($copy);
        }

        // cascade: ['persist'] on Page::$contentArea commits the new area too.
        $this->em->persist($model);
        $this->em->flush();

        return new JsonResponse([
            'id' => $model->getId(),
            'title' => $model->getTitle(),
            'sectionCount' => \count($sections),
            'builderUrl' => '/admin/page/' . $model->getId(),
        ]);
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
