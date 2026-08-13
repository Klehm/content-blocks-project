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
use ContentBlocks\Security\AccessCheckerInterface;
use ContentBlocks\Security\ContentBlocksAccessDeniedException;
use ContentBlocks\SectionTemplate\IncompatibleTemplateException;
use ContentBlocks\SectionTemplate\SectionTemplateSerializerInterface;
use ContentBlocks\SectionTemplate\UnsupportedTemplateFormatException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

/**
 * Copy / paste of a section or a block:
 *
 *  - GET  /section/{id}/copy   Envelope for the editor's clipboard
 *  - GET  /block/{id}/copy     Idem, one level down
 *  - POST /area/{id}/paste     Replay an envelope into this area, as draft
 *
 * The clipboard itself is **not** here: it lives in the editor's `localStorage`,
 * which is what makes a copy survive a page change. Copying therefore reads
 * nothing more than a snapshot, and pasting receives that snapshot back from the
 * browser — user-writable by construction. Hence the asymmetry in trust: copy is
 * a plain read gated by canEdit() on the source, while paste treats its body as
 * input and routes every block through its own form (see
 * {@see \ContentBlocks\Clipboard\BlockDataReplayer}).
 *
 * Paste writes to *draft* state on the target area, like every other structural
 * op: Publish commits it, Discard reverts it. Authorization is canEdit() on the
 * **target** area — the area the content lands in, which for a cross-area paste
 * is not the one it came from.
 *
 * @internal The routes are the contract, not this class. See FREEZE-AUDIT.md.
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
     * Body: `{ payload: <envelope>, targetSectionId?: int, targetBlockId?: int }`.
     *
     * The target ids are what the editor had selected — the builder's sidebar is
     * the single source of that — and they answer *where*. A section lands after
     * the selected section (or at the end of the area); a block lands after the
     * selected block, or at the end of the selected section's first column. A
     * block paste with no selection at all has no answer and is refused with
     * `no_target` rather than guessing.
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

        // Both ids are resolved against the target area, so a hand-crafted body
        // cannot use an area it may edit to place content inside one it may not.
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
     * @param array<string, mixed> $payload
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
