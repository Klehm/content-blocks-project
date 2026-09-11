<?php

declare(strict_types=1);

namespace ContentBlocks\Controller;

use ContentBlocks\BlockType\BlockTypeRegistry;
use ContentBlocks\Entity\Column;
use ContentBlocks\Entity\ContentArea;
use ContentBlocks\Entity\Section;
use ContentBlocks\History\ActionJournal;
use ContentBlocks\History\JournalScope;
use ContentBlocks\Rendering\BlockRendererInterface;
use ContentBlocks\Rendering\RenderContext;
use ContentBlocks\Section\SectionClonerInterface;
use ContentBlocks\Security\AccessCheckerInterface;
use ContentBlocks\Security\ContentBlocksAccessDeniedException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

/**
 * AJAX endpoints for structural operations on Sections.
 *
 * @see docs/internals/publishing.md#every-structural-op-writes-to-draft
 *
 * @internal the routes are the contract, not this class
 */
#[Route('/_content-blocks')]
final class SectionsController
{
    use CsrfProtectedTrait;

    private const LAYOUT_PRESETS = [
        Section::LAYOUT_FULL => ['col-12'],
        Section::LAYOUT_TWO_COLS => ['col-6', 'col-6'],
        Section::LAYOUT_THREE_COLS => ['col-4', 'col-4', 'col-4'],
    ];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AccessCheckerInterface $accessChecker,
        private readonly CsrfTokenManagerInterface $csrfTokenManager,
        private readonly SectionClonerInterface $sectionCloner,
        private readonly BlockRendererInterface $blockRenderer,
        private readonly BlockTypeRegistry $blockTypeRegistry,
        private readonly ActionJournal $journal,
    ) {
    }

    private function getCsrfTokenManager(): CsrfTokenManagerInterface
    {
        return $this->csrfTokenManager;
    }

    #[Route('/area/{id}/sections', name: 'content_blocks_section_create', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function create(int $id, Request $request): JsonResponse
    {
        if ($error = $this->csrfFailureOrNull($request)) {
            return $error;
        }

        $area = $this->em->find(ContentArea::class, $id);
        if (!$area) {
            return new JsonResponse(['error' => 'ContentArea not found'], Response::HTTP_NOT_FOUND);
        }

        if (!$this->accessChecker->canEdit($area)) {
            throw new ContentBlocksAccessDeniedException();
        }

        $payload = json_decode($request->getContent(), true) ?? [];
        $layout = $payload['layout'] ?? Section::LAYOUT_FULL;

        if (!isset(self::LAYOUT_PRESETS[$layout])) {
            return new JsonResponse(['error' => 'Unknown layout'], Response::HTTP_BAD_REQUEST);
        }

        return $this->journal->record($area, 'section.create', JournalScope::structure(), function () use ($area, $layout): JsonResponse {
            $section = new Section();
            $section->setLayout($layout);
            $section->setPreviewPosition($this->nextPreviewPosition($area));
            $area->addSection($section);

            foreach (self::LAYOUT_PRESETS[$layout] as $i => $preset) {
                $column = new Column();
                $column->setPreset($preset);
                $column->setPreviewPosition($i);
                $section->addColumn($column);
            }

            $this->em->persist($section);
            $this->em->flush();

            return new JsonResponse(['id' => $section->getId()]);
        });
    }

    #[Route('/section/{id}/move', name: 'content_blocks_section_move', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function move(int $id, Request $request): JsonResponse
    {
        if ($error = $this->csrfFailureOrNull($request)) {
            return $error;
        }

        $section = $this->em->find(Section::class, $id);
        if (!$section) {
            return new JsonResponse(['error' => 'Section not found'], Response::HTTP_NOT_FOUND);
        }

        $area = $section->getContentArea();
        if (!$area || !$this->accessChecker->canEdit($area)) {
            throw new ContentBlocksAccessDeniedException();
        }

        $payload = json_decode($request->getContent(), true) ?? [];
        $direction = $payload['direction'] ?? null;
        $rawPosition = $payload['position'] ?? null;

        return $this->journal->record($area, 'section.move', JournalScope::structure(), function () use ($area, $section, $direction, $rawPosition): JsonResponse {
            $sections = array_values(array_filter(
                $area->getSections()->toArray(),
                fn (Section $s) => !$s->isDeleted(),
            ));
            usort($sections, fn (Section $a, Section $b) => $a->getPreviewPosition() <=> $b->getPreviewPosition());
            $index = array_search($section, $sections, true);

            // Two dialects: `direction=up|down` for the toolbar arrows (kept
            // for keyboard flows), `position=<int>` for drag & drop.
            if (\is_int($rawPosition)) {
                if ($index === false) {
                    return new JsonResponse(['moved' => false]);
                }
                $without = $sections;
                array_splice($without, $index, 1);
                $insertAt = max(0, min($rawPosition, \count($without)));
                array_splice($without, $insertAt, 0, [$section]);
                foreach ($without as $i => $s) {
                    $s->setPreviewPosition($i);
                }
                $this->em->flush();

                return new JsonResponse(['moved' => true]);
            }

            if (!\in_array($direction, ['up', 'down'], true)) {
                return new JsonResponse(['error' => 'Invalid direction or position'], Response::HTTP_BAD_REQUEST);
            }

            $other = match ($direction) {
                'up' => $index > 0 ? $sections[$index - 1] : null,
                'down' => $index < \count($sections) - 1 ? $sections[$index + 1] : null,
            };

            if ($other === null) {
                return new JsonResponse(['moved' => false]);
            }

            $tmp = $section->getPreviewPosition();
            $section->setPreviewPosition($other->getPreviewPosition());
            $other->setPreviewPosition($tmp);

            $this->em->flush();

            return new JsonResponse(['moved' => true]);
        });
    }

    #[Route('/section/{id}/duplicate', name: 'content_blocks_section_duplicate', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function duplicate(int $id, Request $request): JsonResponse
    {
        if ($error = $this->csrfFailureOrNull($request)) {
            return $error;
        }

        $section = $this->em->find(Section::class, $id);
        if (!$section) {
            return new JsonResponse(['error' => 'Section not found'], Response::HTTP_NOT_FOUND);
        }

        $area = $section->getContentArea();
        if (!$area || !$this->accessChecker->canEdit($area)) {
            throw new ContentBlocksAccessDeniedException();
        }

        return $this->journal->record($area, 'section.duplicate', JournalScope::structure(), function () use ($area, $section): JsonResponse {
            // Inserted right after the source, siblings re-indexed so positions
            // stay dense. The cloner is shared with the replace-content flow.
            $copy = $this->sectionCloner->cloneSection($section);

            $siblings = array_values(array_filter(
                $area->getSections()->toArray(),
                fn (Section $s) => !$s->isDeleted(),
            ));
            usort($siblings, fn (Section $a, Section $b) => $a->getPreviewPosition() <=> $b->getPreviewPosition());

            $sourceIndex = array_search($section, $siblings, true);
            $insertAt = $sourceIndex === false ? \count($siblings) : $sourceIndex + 1;
            array_splice($siblings, $insertAt, 0, [$copy]);
            foreach ($siblings as $i => $s) {
                $s->setPreviewPosition($i);
            }

            $area->addSection($copy);
            $this->em->persist($copy);
            $this->em->flush();

            // `sourceId` tells the overlay which node to anchor the copy after.
            $response = ['id' => $copy->getId(), 'sourceId' => $section->getId()];

            if ($this->sectionSupportsHotReload($copy)) {
                $response['hotReload'] = true;
                $response['html'] = $this->blockRenderer->renderSection($copy, RenderContext::forPreview());
            } else {
                $response['hotReload'] = false;
            }

            return new JsonResponse($response);
        });
    }

    /**
     * One JS-dependent block forces a full iframe reload so its init pass
     * runs. An empty section trivially qualifies.
     */
    private function sectionSupportsHotReload(Section $section): bool
    {
        foreach ($section->getColumns() as $column) {
            foreach ($column->getBlocks() as $block) {
                if ($block->isDeleted()) {
                    continue;
                }
                $type = $block->getType();
                if (!$this->blockTypeRegistry->has($type)
                    || !$this->blockTypeRegistry->get($type)->supportsPreviewHotReload()) {
                    return false;
                }
            }
        }

        return true;
    }

    #[Route('/section/{id}', name: 'content_blocks_section_delete', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    public function delete(int $id, Request $request): JsonResponse
    {
        if ($error = $this->csrfFailureOrNull($request)) {
            return $error;
        }

        $section = $this->em->find(Section::class, $id);
        if (!$section) {
            return new JsonResponse(['error' => 'Section not found'], Response::HTTP_NOT_FOUND);
        }

        $area = $section->getContentArea();
        if (!$area || !$this->accessChecker->canEdit($area)) {
            throw new ContentBlocksAccessDeniedException();
        }

        return $this->journal->record($area, 'section.delete', JournalScope::structure(), function () use ($section): JsonResponse {
            // Soft-delete in draft. The em->remove() runs at publish time.
            $section->setDeleted(true);
            $this->em->flush();

            return new JsonResponse(['deleted' => true]);
        });
    }

    /**
     * Undo of a soft-delete. 404s once Publish has physically removed the row.
     *
     * @see docs/internals/publishing.md#every-structural-op-writes-to-draft
     */
    #[Route('/section/{id}/restore', name: 'content_blocks_section_restore', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function restore(int $id, Request $request): JsonResponse
    {
        if ($error = $this->csrfFailureOrNull($request)) {
            return $error;
        }

        $section = $this->em->find(Section::class, $id);
        if (!$section) {
            return new JsonResponse(['error' => 'Section not found'], Response::HTTP_NOT_FOUND);
        }

        $area = $section->getContentArea();
        if (!$area || !$this->accessChecker->canEdit($area)) {
            throw new ContentBlocksAccessDeniedException();
        }

        return $this->journal->record($area, 'section.restore', JournalScope::structure(), function () use ($section): JsonResponse {
            $section->setDeleted(false);
            $this->em->flush();

            return new JsonResponse(['restored' => true]);
        });
    }

    private function nextPreviewPosition(ContentArea $area): int
    {
        $max = -1;
        foreach ($area->getSections() as $section) {
            $max = max($max, $section->getPreviewPosition());
        }

        return $max + 1;
    }
}
