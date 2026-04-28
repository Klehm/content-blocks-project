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

        if (!\in_array($direction, ['up', 'down'], true)) {
            return new JsonResponse(['error' => 'Invalid direction'], Response::HTTP_BAD_REQUEST);
        }

        $sections = $area->getSections()->toArray();
        usort($sections, fn (Section $a, Section $b) => $a->getPreviewPosition() <=> $b->getPreviewPosition());

        $index = array_search($section, $sections, true);

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
