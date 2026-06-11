<?php

declare(strict_types=1);

namespace ContentBlocks\Controller;

use ContentBlocks\BlockType\BlockTypeRegistry;
use ContentBlocks\Entity\Block;
use ContentBlocks\Entity\Column;
use ContentBlocks\Rendering\BlockRenderer;
use ContentBlocks\Rendering\RenderMode;
use ContentBlocks\Security\AccessCheckerInterface;
use ContentBlocks\Security\ContentBlocksAccessDeniedException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

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
        private readonly TranslatorInterface $translator,
        private readonly BlockRenderer $blockRenderer,
    ) {
    }

    private function getCsrfTokenManager(): CsrfTokenManagerInterface
    {
        return $this->csrfTokenManager;
    }

    #[Route('/types', name: 'content_blocks_block_types', methods: ['GET'])]
    public function types(): JsonResponse
    {
        $list = [];
        foreach ($this->blockTypeRegistry->getChoices() as $type => $label) {
            $list[] = [
                'type' => $type,
                'label' => $label instanceof TranslatableInterface
                    ? $label->trans($this->translator)
                    : $this->translator->trans((string) $label),
            ];
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

        // Mirror BlockRenderController's policy: a static / CSS-only block can
        // be inserted into the preview in place (no full reload), so ship its
        // rendered markup. A JS-dependent block opts out and the builder falls
        // back to a full reload so its scripts run.
        if ($blockType->supportsPreviewHotReload()) {
            return new JsonResponse([
                'id' => $block->getId(),
                'hotReload' => true,
                'html' => $this->blockRenderer->renderBlock($block, RenderMode::PREVIEW),
            ]);
        }

        return new JsonResponse([
            'id' => $block->getId(),
            'hotReload' => false,
        ]);
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

        // Drop deleted siblings from the position math: the frontend's drag
        // logic ignores them too (they're display:none), so the position
        // index agreed on by the iframe is one in the *visible-only* list.
        if ($crossColumn) {
            $sourceBlocks = array_values(array_filter(
                $source->getBlocks()->toArray(),
                fn (Block $b) => $b->getId() !== $block->getId() && !$b->isDeleted(),
            ));
            $this->reindexPreview($sourceBlocks);

            $block->setColumn($target);
        }

        $targetBlocks = array_values(array_filter(
            $target->getBlocks()->toArray(),
            fn (Block $b) => $b->getId() !== $block->getId() && !$b->isDeleted(),
        ));
        usort($targetBlocks, fn (Block $a, Block $b) => $a->getPreviewPosition() <=> $b->getPreviewPosition());

        $position = max(0, min((int) $position, \count($targetBlocks)));
        array_splice($targetBlocks, $position, 0, [$block]);
        $this->reindexPreview($targetBlocks);

        $this->em->flush();

        return new JsonResponse(['moved' => true]);
    }

    #[Route('/block/{id}/duplicate', name: 'content_blocks_block_duplicate', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function duplicate(int $id, Request $request): JsonResponse
    {
        if ($error = $this->csrfFailureOrNull($request)) {
            return $error;
        }

        $block = $this->em->find(Block::class, $id);
        if (!$block) {
            return new JsonResponse(['error' => 'Block not found'], Response::HTTP_NOT_FOUND);
        }

        $column = $block->getColumn();
        $area = $column?->getSection()?->getContentArea();
        if (!$column || !$area || !$this->accessChecker->canEdit($area)) {
            throw new ContentBlocksAccessDeniedException();
        }

        // Copy ends up as a draft-only block (publishedData null) inserted
        // immediately after the source. Re-index sibling positions so the
        // ordering stays dense.
        $copy = new Block();
        $copy->setColumn($column);
        $copy->setType($block->getType());
        $copy->setDraftData($block->getDraftData() ?? $block->getPublishedData() ?? []);

        $siblings = array_values(array_filter(
            $column->getBlocks()->toArray(),
            fn (Block $b) => !$b->isDeleted(),
        ));
        usort($siblings, fn (Block $a, Block $b) => $a->getPreviewPosition() <=> $b->getPreviewPosition());

        $sourceIndex = array_search($block, $siblings, true);
        $insertAt = $sourceIndex === false ? \count($siblings) : $sourceIndex + 1;
        array_splice($siblings, $insertAt, 0, [$copy]);
        $this->reindexPreview($siblings);

        $column->addBlock($copy);
        $this->em->persist($copy);
        $this->em->flush();

        // Mirror create()'s policy: a static / CSS-only copy ships its rendered
        // markup so the overlay can drop it in place (right after the source),
        // no full reload. A JS-dependent block opts out and the builder reloads
        // the whole iframe so its scripts run. `sourceId` tells the overlay
        // which node to anchor the copy after.
        $response = ['id' => $copy->getId(), 'sourceId' => $block->getId()];

        $blockType = $this->blockTypeRegistry->has($copy->getType())
            ? $this->blockTypeRegistry->get($copy->getType())
            : null;

        if ($blockType !== null && $blockType->supportsPreviewHotReload()) {
            $response['hotReload'] = true;
            $response['html'] = $this->blockRenderer->renderBlock($copy, RenderMode::PREVIEW);
        } else {
            $response['hotReload'] = false;
        }

        return new JsonResponse($response);
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

    /**
     * Undo of a soft-delete: flips the draft `deleted` flag back. Only valid
     * while the deletion is still a draft — once publish ran, the row was
     * physically removed and this endpoint 404s (the builder then surfaces
     * its save-error banner).
     */
    #[Route('/block/{id}/restore', name: 'content_blocks_block_restore', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function restore(int $id, Request $request): JsonResponse
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

        $block->setDeleted(false);
        $this->em->flush();

        return new JsonResponse(['restored' => true]);
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
