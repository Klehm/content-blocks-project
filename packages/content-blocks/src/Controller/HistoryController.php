<?php

declare(strict_types=1);

namespace ContentBlocks\Controller;

use ContentBlocks\Entity\ContentArea;
use ContentBlocks\History\ActionJournal;
use ContentBlocks\History\HistoryResult;
use ContentBlocks\History\SidebarOutcome;
use ContentBlocks\Security\AccessCheckerInterface;
use ContentBlocks\Security\ContentBlocksAccessDeniedException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

/**
 * Undo and redo of the last builder action, draft-scoped like every other
 * mutation here.
 *
 * @see docs/internals/history.md
 *
 * @internal the routes are the contract, not this class
 */
#[Route('/_content-blocks')]
final class HistoryController
{
    use CsrfProtectedTrait;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AccessCheckerInterface $accessChecker,
        private readonly ActionJournal $journal,
        private readonly SidebarOutcome $sidebarOutcome,
        private readonly CsrfTokenManagerInterface $csrfTokenManager,
    ) {
    }

    private function getCsrfTokenManager(): CsrfTokenManagerInterface
    {
        return $this->csrfTokenManager;
    }

    #[Route('/area/{id}/undo', name: 'content_blocks_area_undo', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function undo(int $id, Request $request): JsonResponse
    {
        $area = $this->guardAreaWrite($id, $request);

        return $area instanceof JsonResponse
            ? $area
            : $this->answer($area, $this->journal->undo($area), $request);
    }

    #[Route('/area/{id}/redo', name: 'content_blocks_area_redo', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function redo(int $id, Request $request): JsonResponse
    {
        $area = $this->guardAreaWrite($id, $request);

        return $area instanceof JsonResponse
            ? $area
            : $this->answer($area, $this->journal->redo($area), $request);
    }

    /**
     * Always 200: "nothing to undo" is an answer, not a failure, and the
     * builder shows the save-error banner for anything that is not.
     */
    private function answer(ContentArea $area, HistoryResult $result, Request $request): JsonResponse
    {
        /** @var array<string, mixed> $open */
        $open = \is_array($payload = json_decode((string) $request->getContent(), true))
            && \is_array($payload['open'] ?? null) ? $payload['open'] : [];

        $type = \is_string($open['type'] ?? null) ? $open['type'] : null;
        $openId = \is_int($open['id'] ?? null) ? $open['id'] : null;

        return new JsonResponse($result->toArray() + [
            'hasUnpublishedChanges' => $area->hasUnpublishedChanges(),
            'sidebar' => $this->sidebarOutcome->decide($result->appliedOps, $type, $openId),
        ]);
    }

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
