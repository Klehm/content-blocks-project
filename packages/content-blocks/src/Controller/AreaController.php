<?php

declare(strict_types=1);

namespace ContentBlocks\Controller;

use ContentBlocks\Entity\ContentArea;
use ContentBlocks\Security\AccessCheckerInterface;
use ContentBlocks\Security\ContentBlocksAccessDeniedException;
use ContentBlocks\Service\ContentAreaPublisher;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

/**
 * Whole-area operations: publish (promote drafts to public), discard
 * (revert drafts), and state inquiry (used by the cb-builder controller
 * to update the topbar badge / Discard button after a structural op).
 */
#[Route('/_content-blocks')]
final class AreaController
{
    use CsrfProtectedTrait;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AccessCheckerInterface $accessChecker,
        private readonly ContentAreaPublisher $publisher,
        private readonly CsrfTokenManagerInterface $csrfTokenManager,
    ) {
    }

    private function getCsrfTokenManager(): CsrfTokenManagerInterface
    {
        return $this->csrfTokenManager;
    }

    #[Route('/area/{id}/publish', name: 'content_blocks_area_publish', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function publish(int $id, Request $request): JsonResponse
    {
        $area = $this->guardAreaWrite($id, $request);
        if ($area instanceof JsonResponse) {
            return $area;
        }

        $this->publisher->publish($area);

        return new JsonResponse(['hasUnpublishedChanges' => $area->hasUnpublishedChanges()]);
    }

    #[Route('/area/{id}/discard', name: 'content_blocks_area_discard', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function discard(int $id, Request $request): JsonResponse
    {
        $area = $this->guardAreaWrite($id, $request);
        if ($area instanceof JsonResponse) {
            return $area;
        }

        $this->publisher->discardDraft($area);

        return new JsonResponse(['hasUnpublishedChanges' => $area->hasUnpublishedChanges()]);
    }

    #[Route('/area/{id}/state', name: 'content_blocks_area_state', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function state(int $id): JsonResponse
    {
        $area = $this->em->find(ContentArea::class, $id);
        if (!$area) {
            return new JsonResponse(['error' => 'ContentArea not found'], Response::HTTP_NOT_FOUND);
        }

        if (!$this->accessChecker->canEdit($area)) {
            throw new ContentBlocksAccessDeniedException();
        }

        return new JsonResponse(['hasUnpublishedChanges' => $area->hasUnpublishedChanges()]);
    }

    /**
     * Loads the area, validates CSRF + write access, returns either the
     * loaded area or a JsonResponse representing the error to forward.
     */
    private function guardAreaWrite(int $id, Request $request): ContentArea|JsonResponse
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

        return $area;
    }
}
