<?php

declare(strict_types=1);

namespace ContentBlocks\Controller;

use ContentBlocks\BlockType\BlockTypeRegistry;
use ContentBlocks\Entity\Block;
use ContentBlocks\Rendering\BlockRendererInterface;
use ContentBlocks\Rendering\RenderContext;
use ContentBlocks\Security\AccessCheckerInterface;
use ContentBlocks\Security\ContentBlocksAccessDeniedException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Renders one block so the builder can hot-swap it in the iframe. A type that
 * needs its JS to re-run opts out and gets `{ hotReload: false }`.
 *
 * @internal the routes are the contract, not this class
 */
final class BlockRenderController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AccessCheckerInterface $accessChecker,
        private readonly BlockRendererInterface $blockRenderer,
        private readonly BlockTypeRegistry $blockTypeRegistry,
    ) {
    }

    #[Route(
        '/_content-blocks/block/{id}/render',
        name: 'content_blocks_block_render',
        methods: ['GET'],
        requirements: ['id' => '\d+'],
    )]
    public function render(int $id, Request $request): Response
    {
        $block = $this->em->find(Block::class, $id);

        if ($block === null) {
            return new JsonResponse(['hotReload' => false], 404);
        }

        $contentArea = $block->getColumn()?->getSection()?->getContentArea();

        if ($contentArea === null || !$this->accessChecker->canEdit($contentArea)) {
            throw new ContentBlocksAccessDeniedException();
        }

        $type = $block->getType();
        $blockType = $this->blockTypeRegistry->has($type)
            ? $this->blockTypeRegistry->get($type)
            : null;

        // Unknown type or a JS-dependent view: tell the builder to do a full
        // iframe reload instead of swapping just this block.
        if ($blockType === null || !$blockType->supportsPreviewHotReload()) {
            return new JsonResponse(['hotReload' => false]);
        }

        // For the translation workbench, whose preview shows a language other
        // than the page's own. The core only carries the value through.
        $locale = $request->query->get('locale');
        $locale = \is_string($locale) && $locale !== '' ? $locale : null;

        return new JsonResponse([
            'hotReload' => true,
            'type' => $type,
            'html' => $this->blockRenderer->renderBlock($block, RenderContext::forPreview($locale)),
        ]);
    }
}
