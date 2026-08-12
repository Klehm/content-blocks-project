<?php

declare(strict_types=1);

namespace ContentBlocks\I18n\Controller;

use ContentBlocks\Entity\Block;
use ContentBlocks\Entity\ContentArea;
use ContentBlocks\I18n\Machine\MachineTranslator;
use ContentBlocks\I18n\Machine\TranslationProviderRegistry;
use ContentBlocks\I18n\Progress\TranslationInspector;
use ContentBlocks\Security\AccessCheckerInterface;
use ContentBlocks\Security\ContentBlocksAccessDeniedException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Machine translation, at both scopes the editor asks for: one field (or a few)
 * and the whole page.
 *
 * Both land on {@see MachineTranslator}, which batches either into a single
 * provider call — so "translate the page" is one API round trip, not one per
 * string, and the two buttons cannot drift apart in behaviour.
 *
 * Results are written to the **draft**, through the same writer the manual
 * editor uses. Nothing a provider returns bypasses the translatable-field
 * allow-list, and nothing goes public without a Publish.
 */
#[Route('/_content-blocks/i18n')]
final class MachineTranslationController
{
    private const CSRF_TOKEN_ID = 'content_blocks';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AccessCheckerInterface $accessChecker,
        private readonly MachineTranslator $translator,
        private readonly TranslationProviderRegistry $providers,
        private readonly TranslationInspector $inspector,
        private readonly TranslatorInterface $symfonyTranslator,
        private readonly CsrfTokenManagerInterface $csrfTokenManager,
    ) {
    }

    /** The providers wired on this installation, for the picker. */
    #[Route('/providers', name: 'content_blocks_i18n_providers', methods: ['GET'])]
    public function providers(): JsonResponse
    {
        $out = [];

        foreach ($this->providers->all() as $name => $provider) {
            $label = $provider->getLabel();

            $out[] = [
                'name' => $name,
                'label' => $label instanceof TranslatableInterface
                    ? $label->trans($this->symfonyTranslator)
                    : $label,
            ];
        }

        return new JsonResponse(['providers' => $out]);
    }

    /**
     * Translate one block. Body (all optional):
     * `{"paths": [...], "overwrite": false, "provider": "deepl"}`.
     *
     * Omitting `paths` translates every field of the block that is missing or
     * outdated — which is what the per-block button does. Passing a single path
     * is the per-field button; same endpoint, same code path.
     */
    #[Route('/block/{id}/{locale}/translate', name: 'content_blocks_i18n_block_translate', methods: ['POST'], requirements: ['id' => '\d+', 'locale' => '[A-Za-z0-9_-]+'])]
    public function translateBlock(int $id, string $locale, Request $request): JsonResponse
    {
        $csrf = $this->csrfFailureOrNull($request);

        if ($csrf !== null) {
            return $csrf;
        }

        $block = $this->em->find(Block::class, $id);

        if ($block === null) {
            return new JsonResponse(['error' => 'not_found'], Response::HTTP_NOT_FOUND);
        }

        $area = $block->getColumn()?->getSection()?->getContentArea();

        if ($area === null || !$this->accessChecker->canEdit($area)) {
            throw new ContentBlocksAccessDeniedException();
        }

        $payload = $this->payload($request);
        $paths = \is_array($payload['paths'] ?? null) ? array_map(strval(...), $payload['paths']) : null;

        $result = $this->translator->translateBlock(
            $block,
            $locale,
            $paths,
            (bool) ($payload['overwrite'] ?? false),
            $this->providerName($payload),
        );

        $this->em->flush();

        return new JsonResponse([
            'result' => $result->toArray(),
            'block' => $this->inspector->inspectBlock($block, $locale)?->toArray(),
        ]);
    }

    /**
     * Translate the whole page. Body: `{"overwrite": false, "provider": "deepl"}`.
     *
     * Synchronous on purpose: one batched provider call for a page is seconds,
     * not minutes, and an editor who pressed the button wants to see the result
     * rather than a job id. A host translating hundreds of pages at once should
     * drive {@see MachineTranslator} from a worker instead — the console command
     * does exactly that.
     */
    #[Route('/area/{id}/{locale}/translate', name: 'content_blocks_i18n_area_translate', methods: ['POST'], requirements: ['id' => '\d+', 'locale' => '[A-Za-z0-9_-]+'])]
    public function translateArea(int $id, string $locale, Request $request): JsonResponse
    {
        $csrf = $this->csrfFailureOrNull($request);

        if ($csrf !== null) {
            return $csrf;
        }

        $area = $this->em->find(ContentArea::class, $id);

        if ($area === null) {
            return new JsonResponse(['error' => 'not_found'], Response::HTTP_NOT_FOUND);
        }

        if (!$this->accessChecker->canEdit($area)) {
            throw new ContentBlocksAccessDeniedException();
        }

        $payload = $this->payload($request);

        $result = $this->translator->translateArea(
            $area,
            $locale,
            (bool) ($payload['overwrite'] ?? false),
            $this->providerName($payload),
        );

        $this->em->flush();

        return new JsonResponse([
            'result' => $result->toArray(),
            'progress' => $this->inspector->progressForArea($area, $locale)->toArray(),
        ]);
    }

    /** @return array<string, mixed> */
    private function payload(Request $request): array
    {
        $decoded = json_decode($request->getContent(), true);

        return \is_array($decoded) ? $decoded : [];
    }

    /** @param array<string, mixed> $payload */
    private function providerName(array $payload): ?string
    {
        $name = $payload['provider'] ?? null;

        return \is_string($name) && $name !== '' ? $name : null;
    }

    private function csrfFailureOrNull(Request $request): ?JsonResponse
    {
        $token = $request->headers->get('X-CSRF-Token', '');

        if (!$this->csrfTokenManager->isTokenValid(new CsrfToken(self::CSRF_TOKEN_ID, $token))) {
            return new JsonResponse(['error' => 'Invalid CSRF token'], Response::HTTP_FORBIDDEN);
        }

        return null;
    }
}
