<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Model;
use App\Entity\Page;
use ContentBlocks\Entity\ContentArea;
use ContentBlocks\Entity\Section;
use ContentBlocks\Service\SectionCloner;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Environment;

final class PageController
{
    public function __construct(
        private readonly Environment $twig,
        private readonly EntityManagerInterface $em,
        private readonly SectionCloner $sectionCloner,
        private readonly UrlGeneratorInterface $urls,
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
     * Renders a Model's ContentArea. Not a public marketing page — this route
     * exists so the builder iframe has a URL to preview/edit a model's layout
     * (ContentAreaUrlResolver points model areas here).
     */
    #[Route('/model/{id}', name: 'app_model_show', requirements: ['id' => '\d+'])]
    public function showModel(int $id): Response
    {
        $model = $this->em->find(Model::class, $id);

        if (!$model) {
            return new Response('Model not found', 404);
        }

        return new Response($this->twig->render('model/show.html.twig', [
            'model' => $model,
        ]));
    }

    /**
     * Host-side handler for the builder's "Save page as model" topbar action —
     * the receiving end of the package's generic `cb:builder:action` event.
     * Deep-clones the page's ContentArea (via the package's SectionCloner) into
     * a new Model entity so editors can reuse the layout on other pages through
     * the builder's "Insert content" picker.
     */
    #[Route('/admin/page/{id}/save-as-model', name: 'app_page_save_as_model', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function saveAsModel(int $id): Response
    {
        $source = $this->em->find(Page::class, $id);

        if (!$source || !$source->getContentArea()) {
            return new JsonResponse(['error' => 'Page not found'], Response::HTTP_NOT_FOUND);
        }

        $model = new Model();
        $model->setName(($source->getTitle() ?? 'Page') . ' (modèle)');

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

        // cascade: ['persist'] on Model::$contentArea commits the new area too.
        $this->em->persist($model);
        $this->em->flush();

        return new JsonResponse([
            'id' => $model->getId(),
            'name' => $model->getName(),
            'sectionCount' => \count($sections),
            'editUrl' => $this->urls->generate('app_admin_model_update', ['id' => $model->getId()]),
        ]);
    }
}
