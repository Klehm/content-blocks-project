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
 * Renders a single block's preview markup so the builder can hot-swap it in
 * the iframe instead of reloading the whole page after an inline edit.
 *
 * The block type decides via supportsPreviewHotReload() whether its view is
 * safe to swap in place (static / CSS-only markup) or needs a full reload so
 * its JavaScript init runs again. When it isn't, this endpoint answers
 * `{ hotReload: false }` and the builder falls back to reload().
 *
 * @internal The routes are the contract, not this class. See FREEZE-AUDIT.md.
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

        // An optional `?locale=` pins the render language. Empty or absent
        // keeps the historical behaviour — whatever the request's own locale
        // resolves to — so the builder's own hot-swap path is unchanged.
        //
        // It exists for the translation workbench, which swaps a single block
        // in a preview showing a language other than the one the workbench page
        // itself is served in. Anything a locale actually *does* lives in a
        // satellite package; the core only carries the value through.
        $locale = $request->query->get('locale');
        $locale = \is_string($locale) && $locale !== '' ? $locale : null;

        return new JsonResponse([
            'hotReload' => true,
            'type' => $type,
            'html' => $this->blockRenderer->renderBlock($block, RenderContext::forPreview($locale)),
        ]);
    }
}
