<?php

declare(strict_types=1);

namespace ContentBlocks\Controller;

use ContentBlocks\Entity\Block;
use ContentBlocks\Security\AccessCheckerInterface;
use ContentBlocks\Security\ContentBlocksAccessDeniedException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Twig\Environment;

/**
 * Renders the BlockComponent Live wrapper for a given block. The parent
 * cb-builder Stimulus controller fetches this URL when the iframe asks
 * to edit a block (cb:block:edit postMessage), and injects the response
 * HTML into the sidebar.
 *
 * Live Components handles the rest: form rendering, validation, save
 * and cancel actions, which dispatch cb:block:saved / cb:block:cancel
 * browser events caught by cb-builder.
 */
final class BlockSidebarController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AccessCheckerInterface $accessChecker,
        private readonly Environment $twig,
    ) {
    }

    #[Route(
        '/_content-blocks/block/{id}/edit',
        name: 'content_blocks_block_edit',
        methods: ['GET'],
        requirements: ['id' => '\d+'],
    )]
    public function edit(int $id): Response
    {
        $block = $this->em->find(Block::class, $id);

        if ($block === null) {
            return new Response('', 404);
        }

        $contentArea = $block->getColumn()?->getSection()?->getContentArea();

        if ($contentArea === null || !$this->accessChecker->canEdit($contentArea)) {
            throw new ContentBlocksAccessDeniedException();
        }

        return new Response($this->twig->render('@ContentBlocks/builder/sidebar_block.html.twig', [
            'blockId' => $id,
        ]));
    }
}
