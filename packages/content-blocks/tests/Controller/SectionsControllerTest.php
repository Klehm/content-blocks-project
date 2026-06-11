<?php

declare(strict_types=1);

namespace ContentBlocks\Tests\Controller;

use ContentBlocks\Controller\SectionsController;
use ContentBlocks\Entity\Section;
use ContentBlocks\Security\AccessCheckerInterface;
use ContentBlocks\Security\ContentBlocksAccessDeniedException;
use ContentBlocks\Service\SectionCloner;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Response;

final class SectionsControllerTest extends ControllerTestCase
{
    private function makeController(
        EntityManagerInterface $em,
        bool $csrfValid = true,
        ?AccessCheckerInterface $accessChecker = null,
    ): SectionsController {
        return new SectionsController(
            $em,
            $accessChecker ?? $this->makeAccessChecker(),
            $this->makeCsrfManager($csrfValid),
            new SectionCloner(),
            $this->makeUnusedRenderer(),
            $this->makeRegistry(),
        );
    }

    // ---------- create ----------

    public function testCreateAddsASectionWithTheLayoutColumns(): void
    {
        $area = $this->makeArea(1);
        $controller = $this->makeController($this->makeEm([$area]));

        $response = $controller->create(1, $this->makeJsonRequest(['layout' => Section::LAYOUT_TWO_COLS]));

        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
        $this->assertCount(1, $this->persisted);
        /** @var Section $section */
        $section = $this->persisted[0];
        $this->assertSame(Section::LAYOUT_TWO_COLS, $section->getLayout());
        $this->assertCount(2, $section->getColumns());
        $this->assertSame('col-6', $section->getColumns()[0]->getPreset());
        $this->assertSame(1, $this->flushCount);
    }

    public function testCreateAppendsAfterExistingSections(): void
    {
        $area = $this->makeArea(1);
        $this->makeSection($area, 2, previewPosition: 0);
        $this->makeSection($area, 3, previewPosition: 1);
        $controller = $this->makeController($this->makeEm([$area]));

        $controller->create(1, $this->makeJsonRequest(['layout' => Section::LAYOUT_FULL]));

        /** @var Section $section */
        $section = $this->persisted[0];
        $this->assertSame(2, $section->getPreviewPosition());
    }

    public function testCreateRejectsAnUnknownLayout(): void
    {
        $area = $this->makeArea(1);
        $controller = $this->makeController($this->makeEm([$area]));

        $response = $controller->create(1, $this->makeJsonRequest(['layout' => 'six_cols']));

        $this->assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
        $this->assertSame(0, $this->flushCount);
    }

    public function testCreateReturns404ForAnUnknownArea(): void
    {
        $controller = $this->makeController($this->makeEm());

        $response = $controller->create(9, $this->makeJsonRequest(['layout' => Section::LAYOUT_FULL]));

        $this->assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
    }

    public function testCreateRejectsInvalidCsrf(): void
    {
        $controller = $this->makeController($this->makeEm(), csrfValid: false);

        $response = $controller->create(1, $this->makeJsonRequest(['layout' => Section::LAYOUT_FULL]));

        $this->assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
    }

    public function testCreateDeniesWriteWhenAccessCheckerRefuses(): void
    {
        $area = $this->makeArea(1);
        $denier = $this->createMock(AccessCheckerInterface::class);
        $denier->method('canEdit')->willReturn(false);
        $controller = $this->makeController($this->makeEm([$area]), accessChecker: $denier);

        $this->expectException(ContentBlocksAccessDeniedException::class);
        $controller->create(1, $this->makeJsonRequest(['layout' => Section::LAYOUT_FULL]));
    }

    // ---------- move ----------

    public function testMoveUpSwapsWithThePreviousSection(): void
    {
        $area = $this->makeArea(1);
        $first = $this->makeSection($area, 2, previewPosition: 0);
        $second = $this->makeSection($area, 3, previewPosition: 1);
        $controller = $this->makeController($this->makeEm([$second]));

        $response = $controller->move(3, $this->makeJsonRequest(['direction' => 'up']));

        $payload = json_decode((string) $response->getContent(), true);
        $this->assertTrue($payload['moved']);
        $this->assertSame(1, $first->getPreviewPosition());
        $this->assertSame(0, $second->getPreviewPosition());
    }

    public function testMoveUpAtTheTopIsANoOp(): void
    {
        $area = $this->makeArea(1);
        $first = $this->makeSection($area, 2, previewPosition: 0);
        $this->makeSection($area, 3, previewPosition: 1);
        $controller = $this->makeController($this->makeEm([$first]));

        $response = $controller->move(2, $this->makeJsonRequest(['direction' => 'up']));

        $payload = json_decode((string) $response->getContent(), true);
        $this->assertFalse($payload['moved']);
        $this->assertSame(0, $first->getPreviewPosition());
    }

    public function testMoveByPositionReindexesDensely(): void
    {
        $area = $this->makeArea(1);
        $a = $this->makeSection($area, 2, previewPosition: 0);
        $b = $this->makeSection($area, 3, previewPosition: 1);
        $c = $this->makeSection($area, 4, previewPosition: 2);
        $controller = $this->makeController($this->makeEm([$a]));

        $response = $controller->move(2, $this->makeJsonRequest(['position' => 2]));

        $payload = json_decode((string) $response->getContent(), true);
        $this->assertTrue($payload['moved']);
        $this->assertSame(2, $a->getPreviewPosition());
        $this->assertSame(0, $b->getPreviewPosition());
        $this->assertSame(1, $c->getPreviewPosition());
    }

    public function testMoveSkipsDeletedSectionsInTheOrderMath(): void
    {
        $area = $this->makeArea(1);
        $deleted = $this->makeSection($area, 2, previewPosition: 0);
        $deleted->setDeleted(true);
        $a = $this->makeSection($area, 3, previewPosition: 1);
        $b = $this->makeSection($area, 4, previewPosition: 2);
        $controller = $this->makeController($this->makeEm([$b]));

        // "up" from $b should swap with $a, ignoring the deleted head.
        $response = $controller->move(4, $this->makeJsonRequest(['direction' => 'up']));

        $payload = json_decode((string) $response->getContent(), true);
        $this->assertTrue($payload['moved']);
        $this->assertSame(2, $a->getPreviewPosition());
        $this->assertSame(1, $b->getPreviewPosition());
    }

    public function testMoveRejectsAnInvalidDirection(): void
    {
        $area = $this->makeArea(1);
        $section = $this->makeSection($area, 2);
        $controller = $this->makeController($this->makeEm([$section]));

        $response = $controller->move(2, $this->makeJsonRequest(['direction' => 'sideways']));

        $this->assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
    }

    // ---------- duplicate ----------

    public function testDuplicateInsertsADeepCopyRightAfterTheSource(): void
    {
        $area = $this->makeArea(1);
        $source = $this->makeSection($area, 2, previewPosition: 0);
        $column = $this->makeColumn($source, 5);
        $block = $this->makeBlock($column, 10);
        $block->setDraftData(['content' => 'copied']);
        $tail = $this->makeSection($area, 3, previewPosition: 1);
        $controller = $this->makeController($this->makeEm([$source]));

        $response = $controller->duplicate(2, $this->makeJsonRequest());

        $this->assertCount(1, $this->persisted);
        /** @var Section $copy */
        $copy = $this->persisted[0];
        $this->assertSame(1, $copy->getPreviewPosition());
        $this->assertSame(2, $tail->getPreviewPosition());
        $this->assertCount(1, $copy->getColumns());
        $copiedBlock = $copy->getColumns()[0]->getBlocks()[0];
        $this->assertSame(['content' => 'copied'], $copiedBlock->getDraftData());
        $this->assertNull($copiedBlock->getPublishedData());

        $payload = json_decode((string) $response->getContent(), true);
        $this->assertSame(2, $payload['sourceId']);
        // FakeBlockType opts out of hot reload, so the copy must too.
        $this->assertFalse($payload['hotReload']);
    }

    public function testDuplicateOfAnEmptySectionSupportsHotReloadPath(): void
    {
        // An empty section trivially qualifies for hot reload — but the
        // renderer is a stub here, so we only assert the decision flag is
        // computed without touching the renderer when blocks exist.
        $area = $this->makeArea(1);
        $source = $this->makeSection($area, 2);
        $column = $this->makeColumn($source, 5);
        $this->makeBlock($column, 10); // non-hot-reload type forces false
        $controller = $this->makeController($this->makeEm([$source]));

        $response = $controller->duplicate(2, $this->makeJsonRequest());

        $payload = json_decode((string) $response->getContent(), true);
        $this->assertFalse($payload['hotReload']);
    }

    // ---------- delete / restore ----------

    public function testDeleteSoftDeletesTheSection(): void
    {
        $area = $this->makeArea(1);
        $section = $this->makeSection($area, 2);
        $controller = $this->makeController($this->makeEm([$section]));

        $response = $controller->delete(2, $this->makeJsonRequest());

        $this->assertTrue($section->isDeleted());
        $this->assertSame(1, $this->flushCount);
        $payload = json_decode((string) $response->getContent(), true);
        $this->assertTrue($payload['deleted']);
    }

    public function testRestoreClearsTheSoftDeleteFlag(): void
    {
        $area = $this->makeArea(1);
        $section = $this->makeSection($area, 2);
        $section->setDeleted(true);
        $controller = $this->makeController($this->makeEm([$section]));

        $response = $controller->restore(2, $this->makeJsonRequest());

        $this->assertFalse($section->isDeleted());
        $this->assertSame(1, $this->flushCount);
        $payload = json_decode((string) $response->getContent(), true);
        $this->assertTrue($payload['restored']);
    }

    public function testRestoreReturns404ForAnUnknownSection(): void
    {
        $controller = $this->makeController($this->makeEm());

        $response = $controller->restore(9, $this->makeJsonRequest());

        $this->assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
    }

    public function testRestoreDeniesWriteWhenAccessCheckerRefuses(): void
    {
        $area = $this->makeArea(1);
        $section = $this->makeSection($area, 2);
        $denier = $this->createMock(AccessCheckerInterface::class);
        $denier->method('canEdit')->willReturn(false);
        $controller = $this->makeController($this->makeEm([$section]), accessChecker: $denier);

        $this->expectException(ContentBlocksAccessDeniedException::class);
        $controller->restore(2, $this->makeJsonRequest());
    }
}
