<?php

declare(strict_types=1);

namespace ContentBlocks\Controller;

use ContentBlocks\BlockType\BlockTypeRegistry;
use ContentBlocks\Builder\BuilderStructureResolverInterface;
use ContentBlocks\Builder\ConfiguredBuilderStructureResolver;
use ContentBlocks\Builder\StructureRefusal;
use ContentBlocks\Content\ContentManipulationException;
use ContentBlocks\Content\ContentManipulator;
use ContentBlocks\Content\ContentManipulatorInterface;
use ContentBlocks\Content\DraftOrder;
use ContentBlocks\Entity\Column;
use ContentBlocks\Entity\ContentArea;
use ContentBlocks\Entity\Section;
use ContentBlocks\History\ActionJournal;
use ContentBlocks\History\JournalScope;
use ContentBlocks\Section\ColumnSettings;
use ContentBlocks\Security\AccessCheckerInterface;
use ContentBlocks\Security\ContentBlocksAccessDeniedException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

/**
 * AJAX endpoints for a section's columns: add, delete, and their settings.
 * Every write lands in the draft.
 *
 * @see docs/internals/rendering.md#columns-and-tabs
 *
 * @internal the routes are the contract, not this class
 */
final class ColumnsController
{
    use CsrfProtectedTrait;

    public const MAX_COLUMNS = ContentManipulator::MAX_COLUMNS;

    private readonly ContentManipulatorInterface $content;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AccessCheckerInterface $accessChecker,
        private readonly CsrfTokenManagerInterface $csrfTokenManager,
        private readonly ActionJournal $journal,
        ?ContentManipulatorInterface $content = null,
        private readonly BuilderStructureResolverInterface $structure = new ConfiguredBuilderStructureResolver(),
    ) {
        $this->content = $content ?? new ContentManipulator($em, new BlockTypeRegistry());
    }

    private function getCsrfTokenManager(): CsrfTokenManagerInterface
    {
        return $this->csrfTokenManager;
    }

    #[Route('/section/{id}/columns', name: 'content_blocks_column_create', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function create(int $id, Request $request): JsonResponse
    {
        if ($error = $this->csrfFailureOrNull($request)) {
            return $error;
        }

        $section = $this->em->find(Section::class, $id);
        if (!$section) {
            return new JsonResponse(['error' => 'Section not found'], Response::HTTP_NOT_FOUND);
        }

        $area = $this->editableArea($section);
        if (!$this->structure->forArea($area)->canEditColumns()) {
            return StructureRefusal::response();
        }

        if (\count(DraftOrder::columns($section)) >= self::MAX_COLUMNS) {
            return new JsonResponse(['error' => 'too_many_columns'], Response::HTTP_BAD_REQUEST);
        }

        return $this->journal->record($area, 'column.create', JournalScope::structure(), function () use ($section): JsonResponse {
            $column = $this->content->addColumn($section);
            $this->em->flush();

            return new JsonResponse(['id' => $column->getId(), 'columnCount' => \count(DraftOrder::columns($section))]);
        });
    }

    #[Route('/column/{id}/delete', name: 'content_blocks_column_delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function delete(int $id, Request $request): JsonResponse
    {
        if ($error = $this->csrfFailureOrNull($request)) {
            return $error;
        }

        $column = $this->em->find(Column::class, $id);
        $section = $column?->getSection();
        if ($column === null || $section === null) {
            return new JsonResponse(['error' => 'Column not found'], Response::HTTP_NOT_FOUND);
        }
        $area = $this->editableArea($section);
        if (!$this->structure->forArea($area)->canEditColumns()) {
            return StructureRefusal::response();
        }

        if ($column->isDeleted()) {
            return new JsonResponse(['deleted' => false]);
        }

        // A section with no column would have nowhere to put a block.
        if (\count(DraftOrder::columns($section)) <= 1) {
            return new JsonResponse(['error' => 'last_column'], Response::HTTP_BAD_REQUEST);
        }

        return $this->journal->record($area, 'column.delete', JournalScope::structure(), function () use ($column, $section): JsonResponse {
            // A flag, like every delete: the blocks go with the column, the
            // public page keeps both until Publish, Discard brings them back.
            try {
                $this->content->deleteColumn($column);
            } catch (ContentManipulationException $e) {
                return new JsonResponse(['error' => $e->reason], Response::HTTP_BAD_REQUEST);
            }
            $this->em->flush();

            return new JsonResponse(['deleted' => true, 'columnCount' => \count(DraftOrder::columns($section))]);
        });
    }

    #[Route('/column/{id}/settings', name: 'content_blocks_column_settings', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function settings(int $id, Request $request): Response
    {
        if ($error = $this->csrfFailureOrNull($request)) {
            return $error;
        }

        $column = $this->em->find(Column::class, $id);
        $section = $column?->getSection();
        if ($column === null || $section === null) {
            return new JsonResponse(['error' => 'Column not found'], Response::HTTP_NOT_FOUND);
        }
        $area = $this->editableArea($section);
        if (!$this->structure->forArea($area)->canEditColumns()) {
            return StructureRefusal::response();
        }

        $payload = json_decode($request->getContent(), true);
        if (!\is_array($payload)) {
            return new JsonResponse(['error' => 'Invalid payload'], Response::HTTP_BAD_REQUEST);
        }

        // Merged over what the column has, so a field a later version adds is
        // not wiped by a client that does not send it.
        $settings = ColumnSettings::sanitize(
            array_replace($column->getEffectiveSettings(preferDraft: true), $payload),
        );

        // Coalesced per column: the label is typed, one keystroke at a time.
        $this->journal->record(
            $area,
            'column.settings',
            JournalScope::columnSettings($column),
            function () use ($column, $settings): void {
                $column->setDraftSettings($settings);
                $this->em->flush();
            },
            'column.settings:' . $id,
        );

        return new Response('', Response::HTTP_NO_CONTENT);
    }

    private function editableArea(Section $section): ContentArea
    {
        $area = $section->getContentArea();
        if ($area === null || !$this->accessChecker->canEdit($area)) {
            throw new ContentBlocksAccessDeniedException();
        }

        return $area;
    }
}
