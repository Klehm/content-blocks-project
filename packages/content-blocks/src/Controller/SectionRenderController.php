<?php

declare(strict_types=1);

namespace ContentBlocks\Controller;

use ContentBlocks\Entity\Section;
use ContentBlocks\Rendering\BlockRendererInterface;
use ContentBlocks\Rendering\RenderContext;
use ContentBlocks\Security\AccessCheckerInterface;
use ContentBlocks\Security\ContentBlocksAccessDeniedException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Renders one section so the builder can hot-reload its style. Always safe:
 * only the wrapper attributes are copied back, never the inner blocks.
 *
 * @internal the routes are the contract, not this class
 */
final class SectionRenderController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AccessCheckerInterface $accessChecker,
        private readonly BlockRendererInterface $blockRenderer,
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
            'html' => $this->blockRenderer->renderSection($section, RenderContext::forPreview()),
        ]);
    }
}
