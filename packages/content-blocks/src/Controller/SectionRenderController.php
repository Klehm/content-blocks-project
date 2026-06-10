<?php

declare(strict_types=1);

namespace ContentBlocks\Controller;

use ContentBlocks\Entity\Section;
use ContentBlocks\Rendering\BlockRenderer;
use ContentBlocks\Rendering\RenderMode;
use ContentBlocks\Security\AccessCheckerInterface;
use ContentBlocks\Security\ContentBlocksAccessDeniedException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Renders a single section's preview markup so the builder can hot-reload its
 * style (wrapper class/style + column widths) in the iframe after a settings
 * change, instead of reloading the whole page.
 *
 * The builder only copies the wrapper attributes from this HTML onto the
 * existing nodes — the inner blocks (and their JS state) are left in place —
 * so a section style change is always safe to hot-reload.
 */
final class SectionRenderController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AccessCheckerInterface $accessChecker,
        private readonly BlockRenderer $blockRenderer,
    ) {
    }

    #[Route(
        '/_content-blocks/section/{id}/render',
        name: 'content_blocks_section_render',
        methods: ['GET'],
        requirements: ['id' => '\d+'],
    )]
    public function render(int $id): Response
    {
        $section = $this->em->find(Section::class, $id);

        if ($section === null) {
            return new JsonResponse(['hotReload' => false], 404);
        }

        $contentArea = $section->getContentArea();

        if ($contentArea === null || !$this->accessChecker->canEdit($contentArea)) {
            throw new ContentBlocksAccessDeniedException();
        }

        return new JsonResponse([
            'hotReload' => true,
            'html' => $this->blockRenderer->renderSection($section, RenderMode::PREVIEW),
        ]);
    }
}
