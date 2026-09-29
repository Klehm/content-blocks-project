<?php

declare(strict_types=1);

namespace ContentBlocks\I18n\Controller;

use ContentBlocks\Entity\ContentArea;
use ContentBlocks\Event\ActionRefusedException;
use ContentBlocks\I18n\Lifecycle\TranslationDrafts;
use ContentBlocks\I18n\Locale\TranslationLocales;
use ContentBlocks\Publishing\ContentAreaPublisherInterface;
use ContentBlocks\Publishing\PublishContext;
use ContentBlocks\Security\AccessCheckerInterface;
use ContentBlocks\Security\ContentBlocksAccessDeniedException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

/**
 * Publishes or discards one language from the workbench. Refused while the
 * page has a draft of its own, which the call would put live with it.
 *
 * @see docs/internals/i18n.md#publishing-one-language
 */
final class LocalePublishController
{
    private const CSRF_TOKEN_ID = 'content_blocks';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AccessCheckerInterface $accessChecker,
        private readonly ContentAreaPublisherInterface $publisher,
        private readonly TranslationDrafts $drafts,
        private readonly TranslationLocales $locales,
        private readonly CsrfTokenManagerInterface $csrfTokenManager,
    ) {
    }

    #[Route('/area/{id}/{locale}/publish', name: 'content_blocks_i18n_area_publish', methods: ['POST'], requirements: ['id' => '\d+', 'locale' => '[A-Za-z0-9_-]+'])]
    public function publish(int $id, string $locale, Request $request): JsonResponse
    {
        return $this->run($id, $locale, $request, function (ContentArea $area, PublishContext $context): void {
            $this->publisher->publish($area, $context);
        });
    }

    #[Route('/area/{id}/{locale}/discard', name: 'content_blocks_i18n_area_discard', methods: ['POST'], requirements: ['id' => '\d+', 'locale' => '[A-Za-z0-9_-]+'])]
    public function discard(int $id, string $locale, Request $request): JsonResponse
    {
        return $this->run($id, $locale, $request, function (ContentArea $area, PublishContext $context): void {
            $this->publisher->discardDraft($area, $context);
        });
    }

    /**
     * @param callable(ContentArea, PublishContext): void $action
     */
    private function run(int $id, string $locale, Request $request, callable $action): JsonResponse
    {
        $token = $request->headers->get('X-CSRF-Token', '');
        if (!$this->csrfTokenManager->isTokenValid(new CsrfToken(self::CSRF_TOKEN_ID, $token))) {
            return new JsonResponse(['error' => 'Invalid CSRF token'], Response::HTTP_FORBIDDEN);
        }

        $area = $this->em->find(ContentArea::class, $id);
        if ($area === null) {
            return new JsonResponse(['error' => 'not_found'], Response::HTTP_NOT_FOUND);
        }
        if (!$this->accessChecker->canEdit($area)) {
            throw new ContentBlocksAccessDeniedException();
        }
        if (!$this->locales->isTarget($locale)) {
            return new JsonResponse(['error' => 'unknown_locale'], Response::HTTP_NOT_FOUND);
        }

        // Publish and discard always act on the page's own draft too: with
        // one pending, a translator would publish or drop an editor's work.
        if ($area->hasUnpublishedChanges()) {
            return new JsonResponse(['error' => 'source_unpublished'], Response::HTTP_CONFLICT);
        }

        try {
            $action($area, PublishContext::withLocales($locale));
        } catch (ActionRefusedException $e) {
            return new JsonResponse(
                ['error' => 'refused', 'message' => $e->getMessage(), 'reasons' => $e->reasons],
                Response::HTTP_CONFLICT,
            );
        }

        return new JsonResponse(['pending' => $this->drafts->hasPending($area, $locale)]);
    }
}
