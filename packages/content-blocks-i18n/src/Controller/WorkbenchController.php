<?php

declare(strict_types=1);

namespace ContentBlocks\I18n\Controller;

use ContentBlocks\Entity\Block;
use ContentBlocks\Entity\ContentArea;
use ContentBlocks\I18n\Locale\TranslationLocales;
use ContentBlocks\I18n\Progress\BlockTranslationView;
use ContentBlocks\I18n\Progress\TranslationInspector;
use ContentBlocks\I18n\Progress\TranslationProgress;
use ContentBlocks\I18n\Storage\TranslationWriter;
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
 * The read/write API the translation workbench runs on.
 *
 * Same conventions as every other builder endpoint: the `/_content-blocks`
 * prefix (so a host firewall pattern covering the builder covers this too),
 * `canEdit()` on the area before anything, and the `content_blocks` CSRF token
 * on every write.
 *
 * The payload is deliberately flat and UI-agnostic — a list of blocks, each a
 * list of fields with source, value and status. Nothing here assumes the
 * workbench layout, so the same endpoints back a side-by-side editor, a
 * per-block sidebar, or a script.
 */
#[Route('/_content-blocks/i18n')]
final class WorkbenchController
{
    private const CSRF_TOKEN_ID = 'content_blocks';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AccessCheckerInterface $accessChecker,
        private readonly TranslationInspector $inspector,
        private readonly TranslationWriter $writer,
        private readonly TranslationLocales $locales,
        private readonly CsrfTokenManagerInterface $csrfTokenManager,
    ) {
    }

    /**
     * The configured locale set plus this area's per-locale progress — what a
     * language switcher needs to render "DE 40%" before anyone opens it.
     */
    #[Route('/area/{id}/locales', name: 'content_blocks_i18n_area_locales', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function locales(int $id): JsonResponse
    {
        $area = $this->area($id);

        if ($area instanceof JsonResponse) {
            return $area;
        }

        $progress = $this->inspector->progressMatrix($area);

        return new JsonResponse([
            'source' => $this->locales->getSourceLocale(),
            'locales' => array_map(
                fn (array $locale): array => $locale + [
                    'progress' => isset($progress[$locale['code']]) ? $progress[$locale['code']]->toArray() : null,
                ],
                $this->locales->toArray(),
            ),
        ]);
    }

    /**
     * Every translatable field of the area in reading order, for one locale.
     *
     * This is the whole workbench in one request. A page of 40 blocks is a few
     * hundred fields — small next to the block payloads the builder already
     * moves — and having it arrive at once is what lets the editor tab from
     * field to field without a round trip per row.
     */
    #[Route('/area/{id}/{locale}', name: 'content_blocks_i18n_area_fields', methods: ['GET'], requirements: ['id' => '\d+', 'locale' => '[A-Za-z0-9_-]+'])]
    public function areaFields(int $id, string $locale): JsonResponse
    {
        $area = $this->area($id);

        if ($area instanceof JsonResponse) {
            return $area;
        }

        if (!$this->locales->isTarget($locale)) {
            return new JsonResponse(['error' => 'unknown_locale'], Response::HTTP_NOT_FOUND);
        }

        $views = $this->inspector->inspectArea($area, $locale);
        $total = new TranslationProgress($locale);

        foreach ($views as $view) {
            $total = $total->plus($view->progress);
        }

        return new JsonResponse([
            'locale' => $locale,
            'source' => $this->locales->getSourceLocale(),
            'progress' => $total->toArray(),
            'blocks' => array_map(static fn (BlockTranslationView $v): array => $v->toArray(), $views),
        ]);
    }

    /**
     * Saves a batch of field values for one block.
     *
     * Body: `{"values": {"<path>": "<text>|null"}}`. **`null` clears** a
     * translation (the field falls back to the source); `""` stores a
     * deliberate blank. See {@see TranslationWriter} — the distinction is
     * load-bearing, not pedantry.
     *
     * Writes to the draft, so Publish commits and Discard reverts, exactly like
     * every other builder edit.
     */
    #[Route('/block/{id}/{locale}', name: 'content_blocks_i18n_block_save', methods: ['POST'], requirements: ['id' => '\d+', 'locale' => '[A-Za-z0-9_-]+'])]
    public function saveBlock(int $id, string $locale, Request $request): JsonResponse
    {
        $csrf = $this->csrfFailureOrNull($request);

        if ($csrf !== null) {
            return $csrf;
        }

        $block = $this->blockForWrite($id);

        if ($block instanceof JsonResponse) {
            return $block;
        }

        $payload = json_decode($request->getContent(), true);
        $values = \is_array($payload) ? ($payload['values'] ?? null) : null;

        if (!\is_array($values)) {
            return new JsonResponse(['error' => 'missing_values'], Response::HTTP_BAD_REQUEST);
        }

        // Anything that is neither a string nor null is refused before it
        // reaches the writer: the shape is the API's contract, and coercing an
        // array or a number into text would store nonsense under a valid path.
        $clean = [];

        foreach ($values as $path => $value) {
            if ($value !== null && !\is_string($value)) {
                return new JsonResponse(['error' => 'invalid_value', 'path' => (string) $path], Response::HTTP_BAD_REQUEST);
            }

            $clean[(string) $path] = $value;
        }

        $result = $this->writer->write($block, $locale, $clean);
        $this->em->flush();

        return new JsonResponse([
            'result' => $result->toArray(),
            'block' => $this->inspector->inspectBlock($block, $locale)?->toArray(),
        ]);
    }

    /**
     * Re-stamps the source digest of fields whose translation is still correct
     * — the "the English changed but the German still says the right thing"
     * action. Body: `{"paths": ["<path>", …]}`.
     */
    #[Route('/block/{id}/{locale}/approve', name: 'content_blocks_i18n_block_approve', methods: ['POST'], requirements: ['id' => '\d+', 'locale' => '[A-Za-z0-9_-]+'])]
    public function approve(int $id, string $locale, Request $request): JsonResponse
    {
        $csrf = $this->csrfFailureOrNull($request);

        if ($csrf !== null) {
            return $csrf;
        }

        $block = $this->blockForWrite($id);

        if ($block instanceof JsonResponse) {
            return $block;
        }

        $payload = json_decode($request->getContent(), true);
        $paths = \is_array($payload) ? ($payload['paths'] ?? null) : null;

        if (!\is_array($paths)) {
            return new JsonResponse(['error' => 'missing_paths'], Response::HTTP_BAD_REQUEST);
        }

        $result = $this->writer->markUpToDate($block, $locale, array_map(strval(...), $paths));
        $this->em->flush();

        return new JsonResponse([
            'result' => $result->toArray(),
            'block' => $this->inspector->inspectBlock($block, $locale)?->toArray(),
        ]);
    }

    private function area(int $id): ContentArea|JsonResponse
    {
        $area = $this->em->find(ContentArea::class, $id);

        if ($area === null) {
            return new JsonResponse(['error' => 'not_found'], Response::HTTP_NOT_FOUND);
        }

        if (!$this->accessChecker->canEdit($area)) {
            throw new ContentBlocksAccessDeniedException();
        }

        return $area;
    }

    /**
     * Resolves a block and authorizes against **its own** area — not one named
     * in the request. A forged block id therefore fails the host's `canEdit()`
     * rather than writing into a page the editor has no rights to.
     */
    private function blockForWrite(int $id): Block|JsonResponse
    {
        $block = $this->em->find(Block::class, $id);

        if ($block === null) {
            return new JsonResponse(['error' => 'not_found'], Response::HTTP_NOT_FOUND);
        }

        $area = $block->getColumn()?->getSection()?->getContentArea();

        if ($area === null || !$this->accessChecker->canEdit($area)) {
            throw new ContentBlocksAccessDeniedException();
        }

        return $block;
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
