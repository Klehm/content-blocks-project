<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Page;
use ContentBlocks\Entity\ContentArea;
use ContentBlocks\Entity\Section;
use ContentBlocks\Service\SectionCloner;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
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

    /**
     * Host-side handler for the builder's "Save as model" topbar action — the
     * receiving end of the package's generic `cb:builder:action` event. Deep-
     * clones the page's ContentArea (via the package's SectionCloner) into a
     * new "(model)" Page so editors can reuse the layout through the "Insert
     * content" picker. Mirrors the Symfony sandbox handler.
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
        $model->setSlug(Page::slugify($source->getTitle() ?? '') . '-model-' . bin2hex(random_bytes(3)));

        $area = new ContentArea();
        $model->setContentArea($area);

        // Clone non-deleted sections in previewPosition order — same filtering
        // the replace-with flow uses, mirroring the editor's current draft.
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
            'builderUrl' => '/admin/pages/' . $model->getId() . '/edit',
        ]);
    }
}
