<?php

declare(strict_types=1);

namespace ContentBlocks\Controller;

use ContentBlocks\Ai\AiAction;
use ContentBlocks\Ai\AiBriefProviderInterface;
use ContentBlocks\Ai\AiFeatures;
use ContentBlocks\Ai\AiProviderInterface;
use ContentBlocks\Ai\AltTextRequest;
use ContentBlocks\Ai\ContentRequest;
use ContentBlocks\Ai\GeneratedContentWriter;
use ContentBlocks\Ai\GenerationRefusedException;
use ContentBlocks\Ai\GenerationResult;
use ContentBlocks\Ai\NullAiBriefProvider;
use ContentBlocks\Ai\TextRequest;
use ContentBlocks\Content\DraftOrder;
use ContentBlocks\Entity\Block;
use ContentBlocks\Entity\ContentArea;
use ContentBlocks\Entity\Section;
use ContentBlocks\History\ActionJournal;
use ContentBlocks\History\JournalScope;
use ContentBlocks\Schema\ContentSchemaBuilder;
use ContentBlocks\Schema\SectionTemplateExamples;
use ContentBlocks\SectionTemplate\SectionTemplateSerializerInterface;
use ContentBlocks\Security\AccessCheckerInterface;
use ContentBlocks\Security\ContentBlocksAccessDeniedException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

/**
 * The builder's AI actions. Each answers 404 while it is switched off or the
 * provider does not support it, so a disabled action is not there at all.
 *
 * @see docs/guide/ai.md
 *
 * @internal the routes are the contract, not this class
 */
final class AiController
{
    use CsrfProtectedTrait;

    /** A field's text or an editor's prompt, never a whole document. */
    private const MAX_TEXT = 20000;
    private const MAX_PROMPT = 4000;
    /** Sections already on the page, handed to the model for context. */
    private const MAX_EXISTING = 30;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AccessCheckerInterface $accessChecker,
        private readonly CsrfTokenManagerInterface $csrfTokenManager,
        private readonly AiFeatures $features,
        private readonly ContentSchemaBuilder $schemas,
        private readonly GeneratedContentWriter $writer,
        private readonly ActionJournal $journal,
        private readonly SectionTemplateSerializerInterface $serializer,
        private readonly AiBriefProviderInterface $brief = new NullAiBriefProvider(),
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    private function getCsrfTokenManager(): CsrfTokenManagerInterface
    {
        return $this->csrfTokenManager;
    }

    /** Rewrite, shorten or change the tone of a text field. Writes nothing. */
    #[Route('/area/{id}/ai/text', name: 'content_blocks_ai_text', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function text(int $id, Request $request): JsonResponse
    {
        $body = [];
        $area = $this->open($id, $request, $body);
        if ($area instanceof JsonResponse) {
            return $area;
        }

        $action = $body['action'] ?? null;
        if (!\is_string($action) || !\in_array($action, AiAction::TEXT, true)) {
            return self::invalid('action');
        }
        if (!$this->features->isEnabled($action)) {
            return self::disabled();
        }

        $text = $body['text'] ?? null;
        if (!\is_string($text) || trim($text) === '' || mb_strlen($text) > self::MAX_TEXT) {
            return self::invalid('text');
        }

        $tone = null;
        if ($action === AiAction::TONE) {
            $tone = $body['tone'] ?? null;
            if (!\is_string($tone) || !\in_array($tone, $this->features->tones(), true)) {
                return self::invalid('tone');
            }
        }

        $block = $this->blockIn($area, $body['blockId'] ?? null);
        $field = \is_string($body['field'] ?? null) ? $body['field'] : null;
        $schema = $block !== null && $field !== null
            ? $this->schemas->fieldSchema($block->getType(), $field, $request->getLocale())
            : null;

        $provider = $this->features->provider();
        \assert($provider instanceof AiProviderInterface);

        return $this->answer(fn (): string => $provider->rewriteText(new TextRequest(
            $action,
            $text,
            $request->getLocale(),
            ($body['html'] ?? false) === true || ($schema['contentMediaType'] ?? null) === 'text/html',
            $tone,
            $block?->getType(),
            $schema !== null ? $field : null,
            \is_string($schema['title'] ?? null) ? $schema['title'] : null,
            \is_int($schema['maxLength'] ?? null) ? $schema['maxLength'] : null,
            $this->brief->briefFor($area),
        )));
    }

    /** Describe an image for its `alt` text. Writes nothing. */
    #[Route('/area/{id}/ai/alt-text', name: 'content_blocks_ai_alt_text', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function altText(int $id, Request $request): JsonResponse
    {
        $body = [];
        $area = $this->open($id, $request, $body);
        if ($area instanceof JsonResponse) {
            return $area;
        }
        if (!$this->features->isEnabled(AiAction::ALT_TEXT)) {
            return self::disabled();
        }

        $src = $body['src'] ?? null;
        if (!\is_string($src) || !self::isImagePath($src)) {
            return self::invalid('src');
        }

        $block = $this->blockIn($area, $body['blockId'] ?? null);
        $provider = $this->features->provider();
        \assert($provider instanceof AiProviderInterface);

        return $this->answer(fn (): string => $provider->describeImage(new AltTextRequest(
            $src,
            self::absoluteUrl($src, $request),
            $request->getLocale(),
            $block?->getType(),
            self::nearbyText($block),
            $this->brief->briefFor($area),
        )));
    }

    /**
     * A section or a page from a prompt, new or revised: written to the draft
     * as one undo step, through the paste replay.
     */
    #[Route('/area/{id}/ai/content', name: 'content_blocks_ai_content', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function content(int $id, Request $request): JsonResponse
    {
        $body = [];
        $area = $this->open($id, $request, $body);
        if ($area instanceof JsonResponse) {
            return $area;
        }

        $action = $body['action'] ?? null;
        if (!\is_string($action) || !\in_array($action, AiAction::CONTENT, true)) {
            return self::invalid('action');
        }
        if (!$this->features->isEnabled($action)) {
            return self::disabled();
        }

        $prompt = $body['prompt'] ?? null;
        if (!\is_string($prompt) || trim($prompt) === '' || mb_strlen($prompt) > self::MAX_PROMPT) {
            return self::invalid('prompt');
        }

        // The selected section, or the one holding the selected block.
        $target = $this->sectionIn($area, $body['targetSectionId'] ?? null)
            ?? $this->blockIn($area, $body['targetBlockId'] ?? null)?->getColumn()?->getSection();
        if ($action === AiAction::EDIT_SECTION && $target === null) {
            return self::invalid('targetSectionId');
        }

        $maxSections = $action === AiAction::EDIT_SECTION ? 1 : $this->features->maxSections();
        $after = $action === AiAction::ADD ? $target : null;
        $replacing = $action === AiAction::EDIT_SECTION ? $target : null;
        $replace = $action === AiAction::EDIT_PAGE;
        $existing = $this->existing($area);
        $current = match ($action) {
            AiAction::EDIT_PAGE => $existing,
            AiAction::EDIT_SECTION => [$this->payloadOf($replacing)],
            default => [],
        };

        $provider = $this->features->provider();
        \assert($provider instanceof AiProviderInterface);
        $locale = $request->getLocale();

        try {
            $document = $provider->generateContent(new ContentRequest(
                $action,
                trim($prompt),
                $this->schemas->build($area, $locale),
                $locale,
                $existing,
                $maxSections,
                $this->brief->briefFor($area),
                $current,
            ));
        } catch (\Throwable $e) {
            return $this->providerFailed($e);
        }

        try {
            $result = $this->journal->record(
                $area,
                'ai.' . $action,
                JournalScope::structure(),
                function () use ($area, $document, $after, $maxSections, $replace, $replacing): GenerationResult {
                    $result = $this->writer->write($area, $document, $after, $maxSections, $replace, $replacing);
                    $this->em->flush();

                    return $result;
                },
            );
        } catch (GenerationRefusedException $e) {
            $status = $e->reason === GenerationRefusedException::SECTIONS_LOCKED
                ? Response::HTTP_CONFLICT
                : Response::HTTP_UNPROCESSABLE_ENTITY;

            return new JsonResponse(['error' => $e->reason], $status);
        }

        return new JsonResponse([
            'sectionIds' => array_map(static fn (Section $s): ?int => $s->getId(), $result->sections),
            'blockIds' => array_map(static fn (Block $b): ?int => $b->getId(), $result->blocks),
            'skippedBlockTypes' => $result->skippedBlockTypes,
            'droppedFields' => $result->droppedFields,
        ]);
    }

    /**
     * CSRF, the area, canEdit() and the JSON body: what every action checks
     * before anything else.
     *
     * @param array<mixed> $body filled with the decoded body
     */
    private function open(int $id, Request $request, array &$body): ContentArea|JsonResponse
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

        $decoded = json_decode($request->getContent(), true);
        if (!\is_array($decoded)) {
            return self::invalid('body');
        }
        $body = $decoded;

        return $area;
    }

    /**
     * @param \Closure(): string $call
     */
    private function answer(\Closure $call): JsonResponse
    {
        try {
            $text = trim($call());
        } catch (\Throwable $e) {
            return $this->providerFailed($e);
        }

        if ($text === '') {
            return new JsonResponse(['error' => 'empty'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return new JsonResponse(['text' => $text]);
    }

    /** Logged in full, said in a word: a provider's message may hold a key. */
    private function providerFailed(\Throwable $e): JsonResponse
    {
        $this->logger?->error('Content Blocks AI provider failed: {message}', ['message' => $e->getMessage(), 'exception' => $e]);

        return new JsonResponse(['error' => 'provider_failed'], Response::HTTP_BAD_GATEWAY);
    }

    /**
     * The draft sections, in the output shape, so the model writes something
     * that fits beside them.
     *
     * @return list<array<string, mixed>>
     */
    private function existing(ContentArea $area): array
    {
        $out = [];
        foreach (\array_slice(DraftOrder::sections($area), 0, self::MAX_EXISTING) as $section) {
            $out[] = $this->payloadOf($section);
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    private function payloadOf(?Section $section): array
    {
        if ($section === null) {
            return [];
        }
        $payload = SectionTemplateExamples::clean($this->serializer->serialize($section)->payload);

        return array_filter($payload, static fn (mixed $v): bool => $v !== null);
    }

    private function blockIn(ContentArea $area, mixed $id): ?Block
    {
        if (!\is_int($id) && !(\is_string($id) && ctype_digit($id))) {
            return null;
        }
        $block = $this->em->find(Block::class, (int) $id);

        return $block !== null && !$block->isDeleted()
            && $block->getColumn()?->getSection()?->getContentArea() === $area ? $block : null;
    }

    private function sectionIn(ContentArea $area, mixed $id): ?Section
    {
        if (!\is_int($id) && !(\is_string($id) && ctype_digit($id))) {
            return null;
        }
        $section = $this->em->find(Section::class, (int) $id);

        return $section !== null && !$section->isDeleted() && $section->getContentArea() === $area ? $section : null;
    }

    /** A stored path or an http(s) URL; never `data:` or `javascript:`. */
    private static function isImagePath(string $src): bool
    {
        if ($src === '' || \strlen($src) > 2048 || preg_match('/[\s"<>]/', $src) === 1) {
            return false;
        }
        $scheme = parse_url($src, \PHP_URL_SCHEME);

        return $scheme === null || $scheme === false || \in_array(strtolower($scheme), ['http', 'https'], true);
    }

    private static function absoluteUrl(string $src, Request $request): string
    {
        if (preg_match('#^https?://#i', $src) === 1) {
            return $src;
        }
        if (str_starts_with($src, '//')) {
            return $request->getScheme() . ':' . $src;
        }

        return $request->getSchemeAndHttpHost() . $request->getBasePath() . '/' . ltrim($src, '/');
    }

    /** Words beside an image that say what it shows, if the block has any. */
    private static function nearbyText(?Block $block): ?string
    {
        $data = $block?->getDraftData() ?? $block?->getPublishedData() ?? [];
        foreach (['caption', 'title', 'heading', 'text'] as $key) {
            $value = $data[$key] ?? null;
            if (\is_string($value) && trim($value) !== '') {
                return mb_substr(trim(strip_tags($value)), 0, 500);
            }
        }

        return null;
    }

    private static function invalid(string $field): JsonResponse
    {
        return new JsonResponse(['error' => 'invalid', 'field' => $field], Response::HTTP_BAD_REQUEST);
    }

    private static function disabled(): JsonResponse
    {
        return new JsonResponse(['error' => 'disabled'], Response::HTTP_NOT_FOUND);
    }
}
