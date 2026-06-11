<?php

declare(strict_types=1);

namespace ContentBlocks\Tests\Controller;

use ContentBlocks\Controller\BlocksController;
use ContentBlocks\Entity\Block;
use ContentBlocks\Entity\Column;
use ContentBlocks\Entity\ContentArea;
use ContentBlocks\Security\AccessCheckerInterface;
use ContentBlocks\Security\ContentBlocksAccessDeniedException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;

final class BlocksControllerTest extends ControllerTestCase
{
    private function makeController(
        EntityManagerInterface $em,
        bool $csrfValid = true,
        ?AccessCheckerInterface $accessChecker = null,
    ): BlocksController {
        return new BlocksController(
            $em,
            $accessChecker ?? $this->makeAccessChecker(),
            $this->makeRegistry(),
            $this->makeCsrfManager($csrfValid),
            $this->createMock(TranslatorInterface::class),
            $this->makeUnusedRenderer(),
        );
    }

    /** A column wired into a full area graph (area #1, section #2, column #3). */
    private function makeGraph(): Column
    {
        $area = $this->makeArea(1);
        $section = $this->makeSection($area, 2);

        return $this->makeColumn($section, 3);
    }

    // ---------- create ----------

    public function testCreatePersistsADraftBlockWithDefaultData(): void
    {
        $column = $this->makeGraph();
        $controller = $this->makeController($this->makeEm([$column]));

        $response = $controller->create(3, $this->makeJsonRequest(['type' => FakeBlockType::TYPE]));

        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
        $this->assertCount(1, $this->persisted);
        $this->assertSame(1, $this->flushCount);

        /** @var Block $block */
        $block = $this->persisted[0];
        $this->assertSame(FakeBlockType::TYPE, $block->getType());
        $this->assertSame(['content' => 'default'], $block->getDraftData());
        $this->assertNull($block->getPublishedData());
        $this->assertSame($column, $block->getColumn());

        $payload = json_decode((string) $response->getContent(), true);
        $this->assertFalse($payload['hotReload']);
    }

    public function testCreateAppendsAfterTheLastSibling(): void
    {
        $column = $this->makeGraph();
        $this->makeBlock($column, 10, previewPosition: 0);
        $this->makeBlock($column, 11, previewPosition: 1);
        $controller = $this->makeController($this->makeEm([$column]));

        $controller->create(3, $this->makeJsonRequest(['type' => FakeBlockType::TYPE]));

        /** @var Block $block */
        $block = $this->persisted[0];
        $this->assertSame(2, $block->getPreviewPosition());
    }

    public function testCreateRejectsUnknownBlockType(): void
    {
        $column = $this->makeGraph();
        $controller = $this->makeController($this->makeEm([$column]));

        $response = $controller->create(3, $this->makeJsonRequest(['type' => 'nope']));

        $this->assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
        $this->assertSame(0, $this->flushCount);
    }

    public function testCreateReturns404ForUnknownColumn(): void
    {
        $controller = $this->makeController($this->makeEm());

        $response = $controller->create(99, $this->makeJsonRequest(['type' => FakeBlockType::TYPE]));

        $this->assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
    }

    public function testCreateRejectsInvalidCsrf(): void
    {
        $controller = $this->makeController($this->makeEm(), csrfValid: false);

        $response = $controller->create(3, $this->makeJsonRequest(['type' => FakeBlockType::TYPE]));

        $this->assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
    }

    public function testCreateDeniesWriteWhenAccessCheckerRefuses(): void
    {
        $column = $this->makeGraph();
        $denier = $this->createMock(AccessCheckerInterface::class);
        $denier->method('canEdit')->willReturn(false);
        $controller = $this->makeController($this->makeEm([$column]), accessChecker: $denier);

        $this->expectException(ContentBlocksAccessDeniedException::class);
        $controller->create(3, $this->makeJsonRequest(['type' => FakeBlockType::TYPE]));
    }

    // ---------- move ----------

    public function testMoveReordersWithinTheSameColumn(): void
    {
        $column = $this->makeGraph();
        $a = $this->makeBlock($column, 10, previewPosition: 0);
        $b = $this->makeBlock($column, 11, previewPosition: 1);
        $c = $this->makeBlock($column, 12, previewPosition: 2);
        $controller = $this->makeController($this->makeEm([$column, $a, $b, $c]));

        // Move the first block to the end.
        $response = $controller->move(10, $this->makeJsonRequest(['toColumnId' => 3, 'position' => 2]));

        $payload = json_decode((string) $response->getContent(), true);
        $this->assertTrue($payload['moved']);
        $this->assertSame(2, $a->getPreviewPosition());
        $this->assertSame(0, $b->getPreviewPosition());
        $this->assertSame(1, $c->getPreviewPosition());
    }

    public function testMoveAcrossColumnsReindexesBothSides(): void
    {
        $area = $this->makeArea(1);
        $section = $this->makeSection($area, 2);
        $source = $this->makeColumn($section, 3, previewPosition: 0);
        $target = $this->makeColumn($section, 4, previewPosition: 1);
        $moving = $this->makeBlock($source, 10, previewPosition: 0);
        $staying = $this->makeBlock($source, 11, previewPosition: 1);
        $existing = $this->makeBlock($target, 12, previewPosition: 0);
        $controller = $this->makeController($this->makeEm([$source, $target, $moving]));

        $response = $controller->move(10, $this->makeJsonRequest(['toColumnId' => 4, 'position' => 0]));

        $payload = json_decode((string) $response->getContent(), true);
        $this->assertTrue($payload['moved']);
        $this->assertSame($target, $moving->getColumn());
        $this->assertSame(0, $moving->getPreviewPosition());
        $this->assertSame(1, $existing->getPreviewPosition());
        // The source's survivor was reindexed to a dense 0-based list.
        $this->assertSame(0, $staying->getPreviewPosition());
    }

    public function testMoveIgnoresDeletedSiblingsInPositionMath(): void
    {
        $column = $this->makeGraph();
        $deleted = $this->makeBlock($column, 10, previewPosition: 0);
        $deleted->setDeleted(true);
        $a = $this->makeBlock($column, 11, previewPosition: 1);
        $b = $this->makeBlock($column, 12, previewPosition: 2);
        $controller = $this->makeController($this->makeEm([$column, $a, $b]));

        // position 1 in the *visible* list = after $b once $a is removed.
        $controller->move(11, $this->makeJsonRequest(['toColumnId' => 3, 'position' => 1]));

        $this->assertSame(0, $b->getPreviewPosition());
        $this->assertSame(1, $a->getPreviewPosition());
    }

    public function testMoveRefusesATargetColumnFromAnotherArea(): void
    {
        $column = $this->makeGraph();
        $block = $this->makeBlock($column, 10);

        $otherArea = $this->makeArea(50);
        $otherSection = $this->makeSection($otherArea, 51);
        $foreignColumn = $this->makeColumn($otherSection, 52);

        $controller = $this->makeController($this->makeEm([$column, $block, $foreignColumn]));

        $response = $controller->move(10, $this->makeJsonRequest(['toColumnId' => 52, 'position' => 0]));

        $this->assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
        $this->assertSame(0, $this->flushCount);
    }

    public function testMoveRequiresAnIntegerToColumnId(): void
    {
        $column = $this->makeGraph();
        $block = $this->makeBlock($column, 10);
        $controller = $this->makeController($this->makeEm([$column, $block]));

        $response = $controller->move(10, $this->makeJsonRequest(['position' => 0]));

        $this->assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
    }

    // ---------- duplicate ----------

    public function testDuplicateInsertsADraftCopyRightAfterTheSource(): void
    {
        $column = $this->makeGraph();
        $source = $this->makeBlock($column, 10, previewPosition: 0);
        $source->setDraftData(['content' => 'hello']);
        $tail = $this->makeBlock($column, 11, previewPosition: 1);
        $controller = $this->makeController($this->makeEm([$column, $source]));

        $response = $controller->duplicate(10, $this->makeJsonRequest());

        $this->assertCount(1, $this->persisted);
        /** @var Block $copy */
        $copy = $this->persisted[0];
        $this->assertSame(['content' => 'hello'], $copy->getDraftData());
        $this->assertNull($copy->getPublishedData());
        $this->assertSame(1, $copy->getPreviewPosition());
        $this->assertSame(2, $tail->getPreviewPosition());

        $payload = json_decode((string) $response->getContent(), true);
        $this->assertSame(10, $payload['sourceId']);
        $this->assertFalse($payload['hotReload']);
    }

    public function testDuplicateFallsBackToPublishedDataWhenNoDraft(): void
    {
        $column = $this->makeGraph();
        $source = $this->makeBlock($column, 10);
        $source->setPublishedData(['content' => 'published']);
        $controller = $this->makeController($this->makeEm([$column, $source]));

        $controller->duplicate(10, $this->makeJsonRequest());

        /** @var Block $copy */
        $copy = $this->persisted[0];
        $this->assertSame(['content' => 'published'], $copy->getDraftData());
    }

    // ---------- delete / restore ----------

    public function testDeleteSoftDeletesTheBlock(): void
    {
        $column = $this->makeGraph();
        $block = $this->makeBlock($column, 10);
        $controller = $this->makeController($this->makeEm([$column, $block]));

        $response = $controller->delete(10, $this->makeJsonRequest());

        $this->assertTrue($block->isDeleted());
        $this->assertSame(1, $this->flushCount);
        $payload = json_decode((string) $response->getContent(), true);
        $this->assertTrue($payload['deleted']);
    }

    public function testRestoreClearsTheSoftDeleteFlag(): void
    {
        $column = $this->makeGraph();
        $block = $this->makeBlock($column, 10);
        $block->setDeleted(true);
        $controller = $this->makeController($this->makeEm([$column, $block]));

        $response = $controller->restore(10, $this->makeJsonRequest());

        $this->assertFalse($block->isDeleted());
        $this->assertSame(1, $this->flushCount);
        $payload = json_decode((string) $response->getContent(), true);
        $this->assertTrue($payload['restored']);
    }

    public function testRestoreReturns404ForAnUnknownBlock(): void
    {
        $controller = $this->makeController($this->makeEm());

        $response = $controller->restore(99, $this->makeJsonRequest());

        $this->assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
    }

    public function testRestoreRejectsInvalidCsrf(): void
    {
        $controller = $this->makeController($this->makeEm(), csrfValid: false);

        $response = $controller->restore(10, $this->makeJsonRequest());

        $this->assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
    }

    public function testRestoreDeniesWriteWhenAccessCheckerRefuses(): void
    {
        $column = $this->makeGraph();
        $block = $this->makeBlock($column, 10);
        $denier = $this->createMock(AccessCheckerInterface::class);
        $denier->method('canEdit')->willReturn(false);
        $controller = $this->makeController($this->makeEm([$column, $block]), accessChecker: $denier);

        $this->expectException(ContentBlocksAccessDeniedException::class);
        $controller->restore(10, $this->makeJsonRequest());
    }

    // ---------- types ----------

    public function testTypesListsTheRegisteredBlockTypes(): void
    {
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturnArgument(0);
        $controller = new BlocksController(
            $this->makeEm(),
            $this->makeAccessChecker(),
            $this->makeRegistry(),
            $this->makeCsrfManager(),
            $translator,
            $this->makeUnusedRenderer(),
        );

        $response = $controller->types();

        $payload = json_decode((string) $response->getContent(), true);
        $this->assertSame([['type' => FakeBlockType::TYPE, 'label' => 'Fake']], $payload['types']);
    }
}
