<?php

declare(strict_types=1);

namespace ContentBlocks\Controller;

use ContentBlocks\BlockType\BlockTypeRegistry;
use ContentBlocks\Entity\Block;
use ContentBlocks\Entity\Column;
use ContentBlocks\Security\AccessCheckerInterface;
use ContentBlocks\Security\ContentBlocksAccessDeniedException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

/**
 * AJAX endpoints for structural operations on Blocks. All writes go to the
 * draft state (draftData / previewPosition / column / deleted) — never to
 * publishedData / position. Promotion runs through ContentAreaPublisher.
 */
#[Route('/_content-blocks')]
final class BlocksController
{
    use CsrfProtectedTrait;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AccessCheckerInterface $accessChecker,
        private readonly BlockTypeRegistry $blockTypeRegistry,
        private readonly CsrfTokenManagerInterface $csrfTokenManager,
    ) {
    }

    private function getCsrfTokenManager(): CsrfTokenManagerInterface
    {
        return $this->csrfTokenManager;
    }

    #[Route('/types', name: 'content_blocks_block_types', methods: ['GET'])]
    public function types(): JsonResponse
    {
        $choices = $this->blockTypeRegistry->getChoices();
        $list = [];
        foreach ($choices as $type => $label) {
            $list[] = ['type' => $type, 'label' => $label];
        }

        return new JsonResponse(['types' => $list]);
    }

    #[Route('/column/{id}/blocks', name: 'content_blocks_block_create', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function create(int $id, Request $request): JsonResponse
    {
        if ($error = $this->csrfFailureOrNull($request)) {
            return $error;
        }

        $column = $this->em->find(Column::class, $id);
        if (!$column) {
            return new JsonResponse(['error' => 'Column not found'], Response::HTTP_NOT_FOUND);
        }

        $area = $column->getSection()?->getContentArea();
        if (!$area || !$this->accessChecker->canEdit($area)) {
            throw new ContentBlocksAccessDeniedException();
        }

        $payload = json_decode($request->getContent(), true) ?? [];
        $type = $payload['type'] ?? null;

        if (!is_string($type) || !$this->blockTypeRegistry->has($type)) {
            return new JsonResponse(['error' => 'Unknown block type'], Response::HTTP_BAD_REQUEST);
        }

        $blockType = $this->blockTypeRegistry->get($type);

        $block = new Block();
        $block->setType($type);
        $block->setDraftData($blockType->getDefaultData());
        $block->setPreviewPosition($this->nextPreviewPosition($column));
        $column->addBlock($block);

        $this->em->persist($block);
        $this->em->flush();

        return new JsonResponse(['id' => $block->getId()]);
    }

    #[Route('/block/{id}/move', name: 'content_blocks_block_move', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function move(int $id, Request $request): JsonResponse
    {
        if ($error = $this->csrfFailureOrNull($request)) {
            return $error;
        }

        $block = $this->em->find(Block::class, $id);
        if (!$block) {
            return new JsonResponse(['error' => 'Block not found'], Response::HTTP_NOT_FOUND);
        }

        $area = $block->getColumn()?->getSection()?->getContentArea();
        if (!$area || !$this->accessChecker->canEdit($area)) {
            throw new ContentBlocksAccessDeniedException();
        }

        $payload = json_decode($request->getContent(), true) ?? [];
        $toColumnId = $payload['toColumnId'] ?? null;
        $position = $payload['position'] ?? 0;

        if (!is_int($toColumnId)) {
            return new JsonResponse(['error' => 'Missing toColumnId'], Response::HTTP_BAD_REQUEST);
        }

        $target = $this->em->find(Column::class, $toColumnId);
        if (!$target) {
            return new JsonResponse(['error' => 'Target column not found'], Response::HTTP_NOT_FOUND);
        }

        $targetArea = $target->getSection()?->getContentArea();
        if (!$targetArea || $targetArea->getId() !== $area->getId()) {
            return new JsonResponse(['error' => 'Target column is not in this ContentArea'], Response::HTTP_FORBIDDEN);
        }

        $source = $block->getColumn();
        $crossColumn = $source !== null && $source->getId() !== $target->getId();

        if ($crossColumn) {
            // Re-index source column (sans le bloc déplacé).
            $sourceBlocks = array_values(array_filter(
                $source->getBlocks()->toArray(),
                fn (Block $b) => $b->getId() !== $block->getId(),
            ));
            $this->reindexPreview($sourceBlocks);

            $block->setColumn($target);
        }

        // Place block in target at position.
        $targetBlocks = array_values(array_filter(
            $target->getBlocks()->toArray(),
            fn (Block $b) => $b->getId() !== $block->getId(),
        ));
        usort($targetBlocks, fn (Block $a, Block $b) => $a->getPreviewPosition() <=> $b->getPreviewPosition());

        $position = max(0, min((int) $position, \count($targetBlocks)));
        array_splice($targetBlocks, $position, 0, [$block]);
        $this->reindexPreview($targetBlocks);

        $this->em->flush();

        return new JsonResponse(['moved' => true]);
    }

    #[Route('/block/{id}', name: 'content_blocks_block_delete', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    public function delete(int $id, Request $request): JsonResponse
    {
        if ($error = $this->csrfFailureOrNull($request)) {
            return $error;
        }

        $block = $this->em->find(Block::class, $id);
        if (!$block) {
            return new JsonResponse(['error' => 'Block not found'], Response::HTTP_NOT_FOUND);
        }

        $area = $block->getColumn()?->getSection()?->getContentArea();
        if (!$area || !$this->accessChecker->canEdit($area)) {
            throw new ContentBlocksAccessDeniedException();
        }

        // Soft-delete in draft. Real removal happens at publish time, OR
        // immediately if the block was never published (publishedData null
        // and discardDraft fires).
        $block->setDeleted(true);
        $this->em->flush();

        return new JsonResponse(['deleted' => true]);
    }

    private function nextPreviewPosition(Column $column): int
    {
        $max = -1;
        foreach ($column->getBlocks() as $block) {
            $max = max($max, $block->getPreviewPosition());
        }

        return $max + 1;
    }

    /**
     * @param list<Block> $blocks
     */
    private function reindexPreview(array $blocks): void
    {
        foreach ($blocks as $i => $block) {
            $block->setPreviewPosition($i);
        }
    }
}
