<?php

declare(strict_types=1);

namespace ContentBlocks\Controller;

use ContentBlocks\Entity\ContentArea;
use ContentBlocks\Event\ActionRefusedException;
use ContentBlocks\Publishing\ContentAreaPublisherInterface;
use ContentBlocks\Publishing\LocaleDraft;
use ContentBlocks\Publishing\LocaleDrafts;
use ContentBlocks\Publishing\PublishContext;
use ContentBlocks\Publishing\UnpublishedChanges;
use ContentBlocks\Security\AccessCheckerInterface;
use ContentBlocks\Security\ContentBlocksAccessDeniedException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

/**
 * Whole-area operations: publish, discard, and the state inquiry the topbar
 * badge reads after a structural op.
 *
 * @see docs/internals/publishing.md#publish-and-discard-semantics
 *
 * @internal the routes are the contract, not this class
 */
final class AreaController
{
    use CsrfProtectedTrait;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AccessCheckerInterface $accessChecker,
        private readonly ContentAreaPublisherInterface $publisher,
        private readonly CsrfTokenManagerInterface $csrfTokenManager,
        private readonly UnpublishedChanges $unpublishedChanges = new UnpublishedChanges(),
        private readonly LocaleDrafts $localeDrafts = new LocaleDrafts(),
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

        $context = self::context($request);
        if ($context instanceof JsonResponse) {
            return $context;
        }

        try {
            $this->publisher->publish($area, $context);
        } catch (ActionRefusedException $e) {
            return self::refused($e);
        }

        return new JsonResponse(['hasUnpublishedChanges' => $this->unpublishedChanges->of($area)]);
    }

    #[Route('/area/{id}/discard', name: 'content_blocks_area_discard', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function discard(int $id, Request $request): JsonResponse
    {
        $area = $this->guardAreaWrite($id, $request);
        if ($area instanceof JsonResponse) {
            return $area;
        }

        $context = self::context($request);
        if ($context instanceof JsonResponse) {
            return $context;
        }

        try {
            $this->publisher->discardDraft($area, $context);
        } catch (ActionRefusedException $e) {
            return self::refused($e);
        }

        return new JsonResponse(['hasUnpublishedChanges' => $this->unpublishedChanges->of($area)]);
    }

    /**
     * Which locales the call covers: `{"locales": ["en"]}` in the body, or
     * every locale when the key is absent, as before the field existed.
     */
    private static function context(Request $request): PublishContext|JsonResponse|null
    {
        $payload = json_decode($request->getContent(), true);
        if (!\is_array($payload) || !\array_key_exists('locales', $payload)) {
            return null;
        }

        $locales = $payload['locales'];
        if (!\is_array($locales) || !array_is_list($locales)
            || array_filter($locales, static fn ($l): bool => !\is_string($l) || $l === '') !== []) {
            return new JsonResponse(['error' => 'invalid_locales'], Response::HTTP_BAD_REQUEST);
        }

        return PublishContext::withLocales(...$locales);
    }

    #[Route('/area/{id}/publish-scope', name: 'content_blocks_area_publish_scope', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function publishScope(int $id): JsonResponse
    {
        $area = $this->em->find(ContentArea::class, $id);
        if (!$area) {
            return new JsonResponse(['error' => 'ContentArea not found'], Response::HTTP_NOT_FOUND);
        }

        if (!$this->accessChecker->canEdit($area)) {
            throw new ContentBlocksAccessDeniedException();
        }

        return new JsonResponse([
            'hasOwnDraft' => $area->hasUnpublishedChanges(),
            'locales' => array_map(
                static fn (LocaleDraft $d): array => ['code' => $d->code, 'label' => $d->label],
                $this->localeDrafts->of($area),
            ),
        ]);
    }

    /** A listener said no: a 409 the builder shows in its snackbar. */
    private static function refused(ActionRefusedException $e): JsonResponse
    {
        return new JsonResponse(
            ['error' => 'refused', 'message' => $e->getMessage(), 'reasons' => $e->reasons],
            Response::HTTP_CONFLICT,
        );
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

        return new JsonResponse([
            'hasUnpublishedChanges' => $this->unpublishedChanges->of($area),
            // A session renewed since the shell rendered holds a new token.
            'csrfToken' => $this->csrfTokenManager->getToken(self::CSRF_TOKEN_ID)->getValue(),
        ]);
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
