<?php

declare(strict_types=1);

namespace ContentBlocks\Controller;

use ContentBlocks\Block\CollectionIdBackfiller;
use ContentBlocks\BlockType\BlockTypeRegistry;
use ContentBlocks\Content\ContentManipulationException;
use ContentBlocks\Content\ContentManipulator;
use ContentBlocks\Content\ContentManipulatorInterface;
use ContentBlocks\Entity\Block;
use ContentBlocks\Entity\Column;
use ContentBlocks\Event\AfterBlockDeleteEvent;
use ContentBlocks\Event\BeforeBlockDeleteEvent;
use ContentBlocks\History\ActionJournal;
use ContentBlocks\History\JournalScope;
use ContentBlocks\Rendering\BlockRendererInterface;
use ContentBlocks\Rendering\RenderContext;
use ContentBlocks\Security\AccessCheckerInterface;
use ContentBlocks\Security\ContentBlocksAccessDeniedException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * AJAX endpoints for structural operations on Blocks.
 *
 * @see docs/internals/publishing.md#every-structural-op-writes-to-draft
 *
 * @internal the routes are the contract, not this class
 */
final class BlocksController
{
    use CsrfProtectedTrait;

    private readonly ContentManipulatorInterface $content;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AccessCheckerInterface $accessChecker,
        private readonly BlockTypeRegistry $blockTypeRegistry,
        private readonly CsrfTokenManagerInterface $csrfTokenManager,
        private readonly TranslatorInterface $translator,
        private readonly BlockRendererInterface $blockRenderer,
        private readonly ActionJournal $journal,
        ?CollectionIdBackfiller $collectionIds = null,
        private readonly ?EventDispatcherInterface $events = null,
        ?ContentManipulatorInterface $content = null,
    ) {
        $this->content = $content ?? new ContentManipulator($em, $blockTypeRegistry, collectionIds: $collectionIds);
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

        return $this->journal->record($area, 'block.create', JournalScope::structure(), function () use ($blockType, $column, $type): JsonResponse {
            $block = $this->content->addBlock($column, $type);
            $this->em->flush();

            // A static block ships its markup for in-place insertion; a
            // JS-dependent one opts out and the builder reloads the iframe.
            if ($blockType->supportsPreviewHotReload()) {
                return new JsonResponse([
                    'id' => $block->getId(),
                    'hotReload' => true,
                    'html' => $this->blockRenderer->renderBlock($block, RenderContext::forPreview()),
                ]);
            }

            return new JsonResponse([
                'id' => $block->getId(),
                'hotReload' => false,
            ]);
        });
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

        return $this->journal->record($area, 'block.move', JournalScope::structure(), function () use ($block, $target, $position): JsonResponse {
            // The iframe's index is one in the visible-only list.
            try {
                $this->content->moveBlock($block, $target, (int) $position);
            } catch (ContentManipulationException) {
                return new JsonResponse(['error' => 'Target column is not in this ContentArea'], Response::HTTP_FORBIDDEN);
            }
            $this->em->flush();

            return new JsonResponse(['moved' => true]);
        });
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

        return $this->journal->record($area, 'block.duplicate', JournalScope::structure(), function () use ($block): JsonResponse {
            $copy = $this->content->duplicateBlock($block);
            $this->em->flush();

            // Same policy as create(); `sourceId` tells the overlay which node
            // to anchor the copy after.
            $response = ['id' => $copy->getId(), 'sourceId' => $block->getId()];

            $blockType = $this->blockTypeRegistry->has($copy->getType())
                ? $this->blockTypeRegistry->get($copy->getType())
                : null;

            if ($blockType !== null && $blockType->supportsPreviewHotReload()) {
                $response['hotReload'] = true;
                $response['html'] = $this->blockRenderer->renderBlock($copy, RenderContext::forPreview());
            } else {
                $response['hotReload'] = false;
            }

            return new JsonResponse($response);
        });
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

        $before = $this->events?->dispatch(new BeforeBlockDeleteEvent($block, $area));
        if ($before?->isRefused()) {
            return new JsonResponse([
                'error' => 'refused',
                'message' => implode(' ', $before->getReasons()),
                'reasons' => $before->getReasons(),
            ], Response::HTTP_CONFLICT);
        }

        $response = $this->journal->record($area, 'block.delete', JournalScope::structure(), function () use ($block): JsonResponse {
            // Real removal happens at Publish, or at Discard if the block was
            // never published.
            $this->content->deleteBlock($block);
            $this->em->flush();

            return new JsonResponse(['deleted' => true]);
        });
        $this->events?->dispatch(new AfterBlockDeleteEvent($block, $area));

        return $response;
    }

    /**
     * Undo of a soft-delete. 404s once Publish has physically removed the row.
     *
     * @see docs/internals/publishing.md#every-structural-op-writes-to-draft
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

        return $this->journal->record($area, 'block.restore', JournalScope::structure(), function () use ($block): JsonResponse {
            $this->content->restoreBlock($block);
            $this->em->flush();

            return new JsonResponse(['restored' => true]);
        });
    }
}
