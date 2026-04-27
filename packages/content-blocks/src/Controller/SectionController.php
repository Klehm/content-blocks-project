<?php

declare(strict_types=1);

namespace ContentBlocks\Controller;

use ContentBlocks\Entity\Section;
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
final class SectionController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AccessCheckerInterface $accessChecker,
        private readonly CsrfTokenManagerInterface $csrfTokenManager,
    ) {
    }

    #[Route('/section/{id}/move/{direction}', name: 'content_blocks_section_move', methods: ['POST'], requirements: ['direction' => 'up|down'])]
    public function move(int $id, string $direction, Request $request): JsonResponse
    {
        if (!$this->isCsrfTokenValid($request)) {
            return new JsonResponse(['error' => 'Invalid CSRF token'], Response::HTTP_FORBIDDEN);
        }

        $section = $this->em->find(Section::class, $id);

        if (!$section) {
            return new JsonResponse(['error' => 'Section not found'], Response::HTTP_NOT_FOUND);
        }

        if (!$this->accessChecker->canEdit($section->getContentArea())) {
            throw new ContentBlocksAccessDeniedException();
        }

        $contentArea = $section->getContentArea();
        $sections = $contentArea->getSections()->toArray();
        usort($sections, fn (Section $a, Section $b) => $a->getPosition() <=> $b->getPosition());

        $index = array_search($section, $sections, true);

        if ($direction === 'up' && $index > 0) {
            $other = $sections[$index - 1];
        } elseif ($direction === 'down' && $index < \count($sections) - 1) {
            $other = $sections[$index + 1];
        } else {
            return new JsonResponse(['moved' => false]);
        }

        $tmpPos = $section->getPosition();
        $section->setPosition($other->getPosition());
        $other->setPosition($tmpPos);

        $this->em->flush();

        return new JsonResponse(['moved' => true]);
    }

    #[Route('/section/{id}/delete', name: 'content_blocks_section_delete', methods: ['POST'])]
    public function delete(int $id, Request $request): JsonResponse
    {
        if (!$this->isCsrfTokenValid($request)) {
            return new JsonResponse(['error' => 'Invalid CSRF token'], Response::HTTP_FORBIDDEN);
        }

        $section = $this->em->find(Section::class, $id);

        if (!$section) {
            return new JsonResponse(['error' => 'Section not found'], Response::HTTP_NOT_FOUND);
        }

        if (!$this->accessChecker->canEdit($section->getContentArea())) {
            throw new ContentBlocksAccessDeniedException();
        }

        $this->em->remove($section);
        $this->em->flush();

        return new JsonResponse(['deleted' => true]);
    }

    #[Route('/sections/reorder', name: 'content_blocks_sections_reorder', methods: ['POST'])]
    public function reorder(Request $request): JsonResponse
    {
        if (!$this->isCsrfTokenValid($request)) {
            return new JsonResponse(['error' => 'Invalid CSRF token'], Response::HTTP_FORBIDDEN);
        }

        $payload = json_decode($request->getContent(), true);
        $sectionIds = $payload['sectionIds'] ?? [];

        if (empty($sectionIds)) {
            return new JsonResponse(['reordered' => false]);
        }

        // Load all sections and verify they belong to the same ContentArea
        $contentArea = null;
        $sections = [];
        foreach ($sectionIds as $id) {
            $section = $this->em->find(Section::class, $id);
            if (!$section) {
                return new JsonResponse(['error' => 'Section not found'], Response::HTTP_NOT_FOUND);
            }

            if ($contentArea === null) {
                $contentArea = $section->getContentArea();
                if (!$this->accessChecker->canEdit($contentArea)) {
                    throw new ContentBlocksAccessDeniedException();
                }
            } elseif ($section->getContentArea()->getId() !== $contentArea->getId()) {
                return new JsonResponse(['error' => 'Sections do not belong to the same ContentArea'], Response::HTTP_FORBIDDEN);
            }

            $sections[] = $section;
        }

        foreach ($sections as $position => $section) {
            $section->setPosition($position);
        }

        $this->em->flush();

        return new JsonResponse(['reordered' => true]);
    }

    private function isCsrfTokenValid(Request $request): bool
    {
        $token = $request->headers->get('X-CSRF-Token', '');

        return $this->csrfTokenManager->isTokenValid(new CsrfToken('content_blocks', $token));
    }
}
