<?php

declare(strict_types=1);

namespace ContentBlocks\Controller;

use ContentBlocks\Entity\Column;
use ContentBlocks\Entity\ContentArea;
use ContentBlocks\Entity\Section;
use ContentBlocks\Security\AccessCheckerInterface;
use ContentBlocks\Security\ContentBlocksAccessDeniedException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

/**
 * AJAX endpoints for structural operations on Sections. All writes go to
 * the *draft* state (previewPosition / deleted) — never to the public
 * position. Promotion happens via ContentAreaPublisher::publish().
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

        $sections = array_values(array_filter(
            $area->getSections()->toArray(),
            fn (Section $s) => !$s->isDeleted(),
        ));
        usort($sections, fn (Section $a, Section $b) => $a->getPreviewPosition() <=> $b->getPreviewPosition());
        $index = array_search($section, $sections, true);

        // The endpoint speaks two dialects:
        //  - direction=up|down (legacy, used by the toolbar arrows we're
        //    keeping for keyboard-/no-pointer flows)
        //  - position=<int> (used by drag & drop, where the target index is
        //    known up front)
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

        // Deep-copy: section + draft settings + columns + non-deleted blocks.
        // The copy is inserted immediately after the source by re-indexing
        // sibling sections so positions stay dense. Settings land in the
        // draft slot so the copy starts as an unpublished draft.
        $copy = new Section();
        $copy->setLayout($section->getLayout());
        $sourceSettings = $section->getDraftSettings() ?? $section->getPublishedSettings();
        if ($sourceSettings !== null && $sourceSettings !== []) {
            $copy->setDraftSettings($sourceSettings);
        }

        foreach ($section->getColumns() as $column) {
            $columnCopy = new Column();
            $columnCopy->setPreset($column->getPreset());
            $columnCopy->setPreviewPosition($column->getPreviewPosition());

            foreach ($column->getBlocks() as $block) {
                if ($block->isDeleted()) {
                    continue;
                }
                $blockCopy = new Block();
                $blockCopy->setType($block->getType());
                $blockCopy->setDraftData($block->getDraftData() ?? $block->getPublishedData() ?? []);
                $blockCopy->setPreviewPosition($block->getPreviewPosition());
                $columnCopy->addBlock($blockCopy);
            }

            $copy->addColumn($columnCopy);
        }

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

        return new JsonResponse(['id' => $copy->getId()]);
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

        // Soft-delete in draft. The actual em->remove() runs at publish time.
        $section->setDeleted(true);
        $this->em->flush();

        return new JsonResponse(['deleted' => true]);
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
