<?php

declare(strict_types=1);

namespace ContentBlocks\Controller;

use ContentBlocks\Clipboard\BlockSnapshotSerializerInterface;
use ContentBlocks\Clipboard\ClipboardEnvelope;
use ContentBlocks\Clipboard\ClipboardPaster;
use ContentBlocks\Clipboard\IncompatibleClipboardVersionException;
use ContentBlocks\Clipboard\NoPasteTargetException;
use ContentBlocks\Clipboard\PasteResult;
use ContentBlocks\Clipboard\UnreadableClipboardException;
use ContentBlocks\Entity\Block;
use ContentBlocks\Entity\Column;
use ContentBlocks\Entity\ContentArea;
use ContentBlocks\Entity\Section;
use ContentBlocks\SectionTemplate\IncompatibleTemplateException;
use ContentBlocks\SectionTemplate\SectionTemplateSerializerInterface;
use ContentBlocks\SectionTemplate\UnsupportedTemplateFormatException;
use ContentBlocks\Security\AccessCheckerInterface;
use ContentBlocks\Security\ContentBlocksAccessDeniedException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

/**
 * Copy and paste of a section or a block. Copy is a plain read; paste treats
 * its body as untrusted input and is gated on the **target** area.
 *
 * @see docs/internals/clipboard.md#why-the-clipboard-needs-a-replayer
 *
 * @internal the routes are the contract, not this class
 */
#[Route('/_content-blocks')]
final class ClipboardController
{
    use CsrfProtectedTrait;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AccessCheckerInterface $accessChecker,
        private readonly SectionTemplateSerializerInterface $sectionSerializer,
        private readonly BlockSnapshotSerializerInterface $blockSerializer,
        private readonly ClipboardPaster $paster,
        private readonly CsrfTokenManagerInterface $csrfTokenManager,
        private readonly int $contentVersion = 1,
    ) {
    }

    private function getCsrfTokenManager(): CsrfTokenManagerInterface
    {
        return $this->csrfTokenManager;
    }

    #[Route(
        '/section/{id}/copy',
        name: 'content_blocks_clipboard_copy_section',
        methods: ['GET'],
        requirements: ['id' => '\d+'],
    )]
    public function copySection(int $id): JsonResponse
    {
        $section = $this->em->find(Section::class, $id);
        if (!$section) {
            return new JsonResponse(['error' => 'Section not found'], Response::HTTP_NOT_FOUND);
        }

        $area = $section->getContentArea();
        if (!$area || !$this->accessChecker->canEdit($area)) {
            throw new ContentBlocksAccessDeniedException();
        }

        $snapshot = $this->sectionSerializer->serialize($section);

        return new JsonResponse($this->envelope(ClipboardEnvelope::SCOPE_SECTION, $snapshot->payload));
    }

    #[Route(
        '/block/{id}/copy',
        name: 'content_blocks_clipboard_copy_block',
        methods: ['GET'],
        requirements: ['id' => '\d+'],
    )]
    public function copyBlock(int $id): JsonResponse
    {
        $block = $this->em->find(Block::class, $id);
        if (!$block) {
            return new JsonResponse(['error' => 'Block not found'], Response::HTTP_NOT_FOUND);
        }

        $area = $block->getColumn()?->getSection()?->getContentArea();
        if (!$area || !$this->accessChecker->canEdit($area)) {
            throw new ContentBlocksAccessDeniedException();
        }

        return new JsonResponse(
            $this->envelope(ClipboardEnvelope::SCOPE_BLOCK, $this->blockSerializer->serialize($block)),
        );
    }

    /**
     * Body: `{ payload, targetSectionId?, targetBlockId? }` — the ids being
     * whatever the sidebar had selected, which is what answers *where*.
     *
     * @see docs/internals/clipboard.md#replay-and-placement
     */
    #[Route(
        '/area/{id}/paste',
        name: 'content_blocks_clipboard_paste',
        methods: ['POST'],
        requirements: ['id' => '\d+'],
    )]
    public function paste(int $id, Request $request): JsonResponse
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

        $body = json_decode($request->getContent(), true);
        $raw = is_array($body) ? ($body['payload'] ?? null) : null;
        if (!is_array($raw)) {
            return $this->unreadable('payload');
        }

        try {
            $envelope = ClipboardEnvelope::fromArray($raw);
            $envelope->assertContentVersion($this->contentVersion);
        } catch (UnreadableClipboardException $e) {
            return $this->unreadable($e->getPart());
        } catch (IncompatibleClipboardVersionException $e) {
            return new JsonResponse([
                'error' => 'incompatible_content_version',
                'copiedVersion' => $e->getCopiedVersion(),
                'currentVersion' => $e->getCurrentVersion(),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        // Resolved against the target area, so a forged body cannot use an
        // area it may edit to place content inside one it may not.
        $targetBlock = $this->resolveBlock($body['targetBlockId'] ?? null, $area);
        $targetSection = $targetBlock?->getColumn()?->getSection()
            ?? $this->resolveSection($body['targetSectionId'] ?? null, $area);

        try {
            $result = $envelope->scope === ClipboardEnvelope::SCOPE_SECTION
                ? $this->paster->pasteSection($envelope->payload, $area, $targetSection)
                : $this->pasteBlock($envelope->payload, $targetSection, $targetBlock);
        } catch (NoPasteTargetException) {
            return new JsonResponse(['error' => 'no_target'], Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (IncompatibleTemplateException $e) {
            return new JsonResponse([
                'error' => 'incompatible_clipboard',
                'missingTypes' => $e->getMissingTypes(),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (UnsupportedTemplateFormatException | UnreadableClipboardException) {
            return $this->unreadable('payload');
        }

        $this->em->persist($result->entity);
        $this->em->flush();

        $section = $result->entity instanceof Section
            ? $result->entity
            : $result->entity->getColumn()?->getSection();

        return new JsonResponse([
            'scope' => $envelope->scope,
            'sectionId' => $section?->getId(),
            'blockId' => $result->entity instanceof Block ? $result->entity->getId() : null,
            'skippedBlockCount' => $result->skippedBlockCount,
            'skippedBlockTypes' => $result->skippedBlockTypes,
            'droppedFields' => $result->droppedFields,
        ]);
    }

    /**
     * A block needs a column to land in, which the selection has to supply: the
     * selected block's own column, or the first column of the selected section.
     *
     * @param array<string, mixed> $payload
     *
     * @throws NoPasteTargetException when nothing is selected
     */
    /**
     * @param array<string, mixed> $payload
     *
     * @throws NoPasteTargetException        when the selection has no column
     * @throws UnreadableClipboardException  when the payload is not a snapshot
     * @throws IncompatibleTemplateException when the block's type is gone
     */
    private function pasteBlock(array $payload, ?Section $section, ?Block $after): PasteResult
    {
        $column = $after?->getColumn() ?? $this->firstColumn($section);
        if (!$column) {
            throw new NoPasteTargetException();
        }

        return $this->paster->pasteBlock($payload, $column, $after);
    }

    private function firstColumn(?Section $section): ?Column
    {
        if (!$section) {
            return null;
        }

        $columns = array_values(array_filter(
            $section->getColumns()->toArray(),
            static fn (Column $column) => !$column->isDeleted(),
        ));
        usort($columns, static fn (Column $a, Column $b) => $a->getPreviewPosition() <=> $b->getPreviewPosition());

        return $columns[0] ?? null;
    }

    private function resolveSection(mixed $id, ContentArea $area): ?Section
    {
        if (!is_int($id) && !(is_string($id) && ctype_digit($id))) {
            return null;
        }

        $section = $this->em->find(Section::class, (int) $id);

        return $section && $section->getContentArea() === $area && !$section->isDeleted() ? $section : null;
    }

    private function resolveBlock(mixed $id, ContentArea $area): ?Block
    {
        if (!is_int($id) && !(is_string($id) && ctype_digit($id))) {
            return null;
        }

        $block = $this->em->find(Block::class, (int) $id);
        $blockArea = $block?->getColumn()?->getSection()?->getContentArea();

        return $block && $blockArea === $area && !$block->isDeleted() ? $block : null;
    }

    /**
     * @param ClipboardEnvelope::SCOPE_* $scope
     * @param array<string, mixed>       $payload
     *
     * @return array<string, mixed>
     */
    private function envelope(string $scope, array $payload): array
    {
        return (new ClipboardEnvelope($scope, $payload, $this->contentVersion))->toArray();
    }

    private function unreadable(string $part): JsonResponse
    {
        return new JsonResponse(
            ['error' => 'unreadable_clipboard', 'part' => $part],
            Response::HTTP_UNPROCESSABLE_ENTITY,
        );
    }
}
