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
 * Machine translation at both scopes the editor asks for, batched into one
 * provider call each and written to the draft.
 *
 * @see docs/internals/i18n.md#machine-translation-is-a-seam
 */
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
     * Body, all optional: `{"paths", "overwrite", "provider"}`. Omitting
     * `paths` translates every missing or outdated field of the block.
     *
     * @see docs/internals/i18n.md#machine-translation-is-a-seam
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
        // array_values: a JSON object would otherwise arrive keyed, not a list.
        $paths = \is_array($payload['paths'] ?? null)
            ? array_values(array_map(strval(...), $payload['paths']))
            : null;

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
     * Translate the whole page, synchronously: one batched call is seconds,
     * and the editor wants the result rather than a job id.
     *
     * @see docs/internals/i18n.md#machine-translation-is-a-seam
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
