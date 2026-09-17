<?php

declare(strict_types=1);

namespace ContentBlocks\Controller;

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

    /** Past this, neither a grid nor a tab bar is usable. */
    public const MAX_COLUMNS = 20;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AccessCheckerInterface $accessChecker,
        private readonly CsrfTokenManagerInterface $csrfTokenManager,
        private readonly ActionJournal $journal,
    ) {
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

        if (\count(self::liveColumns($section)) >= self::MAX_COLUMNS) {
            return new JsonResponse(['error' => 'too_many_columns'], Response::HTTP_BAD_REQUEST);
        }

        return $this->journal->record($area, 'column.create', JournalScope::structure(), function () use ($section): JsonResponse {
            $position = 0;
            foreach ($section->getColumns() as $existing) {
                $position = max($position, $existing->getPreviewPosition() + 1);
            }

            $column = new Column();
            $column->setPreviewPosition($position);
            $section->addColumn($column);
            $this->em->persist($column);

            $count = self::rebalance($section);
            $this->em->flush();

            return new JsonResponse(['id' => $column->getId(), 'columnCount' => $count]);
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

        if ($column->isDeleted()) {
            return new JsonResponse(['deleted' => false]);
        }

        // A section with no column would have nowhere to put a block.
        if (\count(self::liveColumns($section)) <= 1) {
            return new JsonResponse(['error' => 'last_column'], Response::HTTP_BAD_REQUEST);
        }

        return $this->journal->record($area, 'column.delete', JournalScope::structure(), function () use ($column, $section): JsonResponse {
            // A flag, like every delete: the blocks go with the column, the
            // public page keeps both until Publish, Discard brings them back.
            $column->setDeleted(true);
            $count = self::rebalance($section);
            $this->em->flush();

            return new JsonResponse(['deleted' => true, 'columnCount' => $count]);
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

    /** @return list<Column> */
    private static function liveColumns(Section $section): array
    {
        $columns = array_values(array_filter(
            $section->getColumns()->toArray(),
            static fn (Column $c): bool => !$c->isDeleted(),
        ));
        usort($columns, static fn (Column $a, Column $b): int => $a->getPreviewPosition() <=> $b->getPreviewPosition());

        return $columns;
    }

    /**
     * Equal spans for the live columns: a count change resets an uneven
     * layout, and undo brings it back. Returns the live count.
     */
    private static function rebalance(Section $section): int
    {
        $columns = self::liveColumns($section);
        $preset = 'col-' . max(1, intdiv(12, max(1, \count($columns))));
        foreach ($columns as $column) {
            if ($column->getPreset() !== $preset) {
                $column->setPreset($preset);
            }
        }

        return \count($columns);
    }
}
