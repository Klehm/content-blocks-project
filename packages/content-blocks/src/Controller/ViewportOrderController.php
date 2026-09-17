<?php

declare(strict_types=1);

namespace ContentBlocks\Controller;

use ContentBlocks\Entity\Block;
use ContentBlocks\Entity\Column;
use ContentBlocks\Entity\ContentArea;
use ContentBlocks\Entity\Section;
use ContentBlocks\History\ActionJournal;
use ContentBlocks\History\JournalScope;
use ContentBlocks\Rendering\ViewportOrder;
use ContentBlocks\Security\AccessCheckerInterface;
use ContentBlocks\Security\ContentBlocksAccessDeniedException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

/**
 * Sets or clears the tablet/mobile order of sections and blocks, drawn in the
 * preview at that viewport. Draft only, journalled.
 *
 * @see docs/internals/rendering.md#order-per-viewport
 *
 * @internal the routes are the contract, not this class
 */
final class ViewportOrderController
{
    use CsrfProtectedTrait;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AccessCheckerInterface $accessChecker,
        private readonly CsrfTokenManagerInterface $csrfTokenManager,
        private readonly ActionJournal $journal,
    ) {
    }

    private function getCsrfTokenManager(): CsrfTokenManagerInterface
    {
        return $this->csrfTokenManager;
    }

    /**
     * Body `{viewport, scope: section|block, columnId?, ids}`: `ids` is the new
     * visual order. Siblings left out lose their rank for that viewport.
     */
    #[Route(
        '/area/{id}/viewport-order',
        name: 'content_blocks_viewport_order',
        methods: ['POST'],
        requirements: ['id' => '\d+'],
    )]
    public function reorder(int $id, Request $request): JsonResponse
    {
        if ($error = $this->csrfFailureOrNull($request)) {
            return $error;
        }
        $area = $this->editableArea($id);

        $payload = json_decode($request->getContent(), true);
        $payload = \is_array($payload) ? $payload : [];
        $viewport = $payload['viewport'] ?? null;
        $ids = $payload['ids'] ?? null;
        if (!\in_array($viewport, ViewportOrder::VIEWPORTS, true)
            || !\is_array($ids) || !array_is_list($ids)
            || array_filter($ids, static fn (mixed $v): bool => !\is_int($v)) !== []
            || \count(array_unique($ids)) !== \count($ids)) {
            return new JsonResponse(['error' => 'invalid_payload'], Response::HTTP_BAD_REQUEST);
        }

        $siblings = match ($payload['scope'] ?? null) {
            'section' => $this->sectionsOf($area),
            'block' => $this->blocksOf($area, $payload['columnId'] ?? null),
            default => null,
        };
        if ($siblings === null) {
            return new JsonResponse(['error' => 'invalid_payload'], Response::HTTP_BAD_REQUEST);
        }

        $byId = [];
        foreach ($siblings as $sibling) {
            $byId[$sibling->getId()] = $sibling;
        }
        // Every id must be a sibling: a block from another column is refused,
        // since CSS cannot move it there.
        if (array_diff($ids, array_keys($byId)) !== []) {
            return new JsonResponse(['error' => 'not_siblings'], Response::HTTP_BAD_REQUEST);
        }

        $ranks = array_flip($ids);
        $this->journal->record($area, 'viewport.order', JournalScope::viewportOrder(), function () use ($siblings, $ranks, $viewport): void {
            foreach ($siblings as $sibling) {
                self::setRank($sibling, $viewport, $ranks[$sibling->getId()] ?? null);
            }
            $this->em->flush();
        });

        return new JsonResponse([
            'ok' => true,
            'orders' => $this->orders($siblings),
        ]);
    }

    /** Body `{viewport}`: clears that viewport's order across the area. */
    #[Route(
        '/area/{id}/viewport-order/reset',
        name: 'content_blocks_viewport_order_reset',
        methods: ['POST'],
        requirements: ['id' => '\d+'],
    )]
    public function reset(int $id, Request $request): JsonResponse
    {
        if ($error = $this->csrfFailureOrNull($request)) {
            return $error;
        }
        $area = $this->editableArea($id);

        $payload = json_decode($request->getContent(), true);
        $viewport = \is_array($payload) ? ($payload['viewport'] ?? null) : null;
        if (!\in_array($viewport, ViewportOrder::VIEWPORTS, true)) {
            return new JsonResponse(['error' => 'invalid_payload'], Response::HTTP_BAD_REQUEST);
        }

        $this->journal->record($area, 'viewport.order', JournalScope::viewportOrder(), function () use ($area, $viewport): void {
            foreach ($area->getSections() as $section) {
                self::setRank($section, $viewport, null);
                foreach ($section->getColumns() as $column) {
                    foreach ($column->getBlocks() as $block) {
                        self::setRank($block, $viewport, null);
                    }
                }
            }
            $this->em->flush();
        });

        return new JsonResponse(['ok' => true]);
    }

    private function editableArea(int $id): ContentArea
    {
        $area = $this->em->find(ContentArea::class, $id);
        if (!$area || !$this->accessChecker->canEdit($area)) {
            throw new ContentBlocksAccessDeniedException();
        }

        return $area;
    }

    /**
     * The builder's sections, in draft order.
     *
     * @return list<Section>
     */
    private function sectionsOf(ContentArea $area): array
    {
        $sections = $area->getSections()->toArray();
        usort($sections, static fn (Section $a, Section $b): int => $a->getPreviewPosition() <=> $b->getPreviewPosition());

        return $sections;
    }

    /**
     * A column's blocks in draft order, or null when it is not in the area.
     *
     * @return list<Block>|null
     */
    private function blocksOf(ContentArea $area, mixed $columnId): ?array
    {
        $column = \is_int($columnId) ? $this->em->find(Column::class, $columnId) : null;
        if (!$column instanceof Column || $column->getSection()?->getContentArea()?->getId() !== $area->getId()) {
            return null;
        }

        $blocks = $column->getBlocks()->toArray();
        usort($blocks, static fn (Block $a, Block $b): int => $a->getPreviewPosition() <=> $b->getPreviewPosition());

        return $blocks;
    }

    /** A no-op write stays a no-op, so an untouched draft is not dirtied. */
    private static function setRank(Section|Block $entity, string $viewport, ?int $rank): void
    {
        if ($entity instanceof Section) {
            $current = $entity->getEffectiveSettings(preferDraft: true);
            $next = ViewportOrder::withRank($current, $viewport, $rank);
            if (ViewportOrder::ranks($next) !== ViewportOrder::ranks($current)) {
                $entity->setDraftSettings($next);
            }

            return;
        }

        $current = $entity->getDraftData() ?? $entity->getPublishedData() ?? [];
        $next = ViewportOrder::withRank($current, $viewport, $rank);
        if (ViewportOrder::ranks($next) !== ViewportOrder::ranks($current)) {
            $entity->setDraftData($next);
        }
    }

    /**
     * What the preview sets on each sibling, keyed by id.
     *
     * @param list<Section>|list<Block> $siblings in draft order
     *
     * @return array<int, array<string, string>>
     */
    private function orders(array $siblings): array
    {
        $vars = ViewportOrder::variables(array_map(
            static fn (Section|Block $e): array => ViewportOrder::ranks($e instanceof Section
                ? $e->getEffectiveSettings(preferDraft: true)
                : ($e->getDraftData() ?? $e->getPublishedData())),
            $siblings,
        ));

        $out = [];
        foreach ($siblings as $i => $sibling) {
            $out[(int) $sibling->getId()] = $vars[$i];
        }

        return $out;
    }
}
