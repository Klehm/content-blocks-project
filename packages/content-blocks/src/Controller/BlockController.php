<?php

declare(strict_types=1);

namespace ContentBlocks\Controller;

use ContentBlocks\Entity\Block;
use ContentBlocks\Entity\Column;
use ContentBlocks\Security\AccessCheckerInterface;
use ContentBlocks\Security\ContentBlocksAccessDeniedException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

#[Route('/_content-blocks')]
final class BlockController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AccessCheckerInterface $accessChecker,
        private readonly CsrfTokenManagerInterface $csrfTokenManager,
    ) {
    }

    #[Route('/block/{id}/move', name: 'content_blocks_block_move', methods: ['POST'])]
    public function move(int $id, Request $request): JsonResponse
    {
        if (!$this->isCsrfTokenValid($request)) {
            return new JsonResponse(['error' => 'Invalid CSRF token'], Response::HTTP_FORBIDDEN);
        }

        $block = $this->em->find(Block::class, $id);

        if (!$block) {
            return new JsonResponse(['error' => 'Block not found'], Response::HTTP_NOT_FOUND);
        }

        $contentArea = $block->getColumn()->getSection()->getContentArea();
        if (!$this->accessChecker->canEdit($contentArea)) {
            throw new ContentBlocksAccessDeniedException();
        }

        $payload = json_decode($request->getContent(), true);
        $targetColumnId = $payload['columnId'] ?? null;
        $position = $payload['position'] ?? 0;

        if (!$targetColumnId) {
            return new JsonResponse(['error' => 'Missing columnId'], Response::HTTP_BAD_REQUEST);
        }

        $targetColumn = $this->em->find(Column::class, $targetColumnId);

        if (!$targetColumn) {
            return new JsonResponse(['error' => 'Column not found'], Response::HTTP_NOT_FOUND);
        }

        // Verify target column belongs to the same ContentArea
        $targetContentArea = $targetColumn->getSection()->getContentArea();
        if ($targetContentArea->getId() !== $contentArea->getId()) {
            return new JsonResponse(['error' => 'Column does not belong to this ContentArea'], Response::HTTP_FORBIDDEN);
        }

        $sourceColumn = $block->getColumn();
        $isCrossColumn = $sourceColumn->getId() !== $targetColumn->getId();

        // Reindex source column (exclude the moved block)
        if ($isCrossColumn) {
            $sourceBlocks = array_values(array_filter(
                $sourceColumn->getBlocks()->toArray(),
                fn (Block $b) => $b->getId() !== $block->getId()
            ));
            foreach ($sourceBlocks as $i => $b) {
                $b->setPosition($i);
            }

            // Change column directly — do NOT use removeBlock() because
            // orphanRemoval: true would delete the block on flush
            $block->setColumn($targetColumn);
        }

        // Build target blocks list without the moved block, insert at position
        $targetBlocks = array_values(array_filter(
            $targetColumn->getBlocks()->toArray(),
            fn (Block $b) => $b->getId() !== $block->getId()
        ));
        usort($targetBlocks, fn (Block $a, Block $b) => $a->getPosition() <=> $b->getPosition());

        $position = min($position, \count($targetBlocks));
        array_splice($targetBlocks, $position, 0, [$block]);

        foreach ($targetBlocks as $i => $b) {
            $b->setPosition($i);
        }

        $this->em->flush();

        return new JsonResponse(['moved' => true]);
    }

    private function isCsrfTokenValid(Request $request): bool
    {
        $token = $request->headers->get('X-CSRF-Token', '');

        return $this->csrfTokenManager->isTokenValid(new CsrfToken('content_blocks', $token));
    }
}
