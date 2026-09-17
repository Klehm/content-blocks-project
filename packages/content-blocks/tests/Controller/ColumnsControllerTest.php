<?php

declare(strict_types=1);

namespace ContentBlocks\Tests\Controller;

use ContentBlocks\Controller\ColumnsController;
use ContentBlocks\Entity\Column;
use ContentBlocks\Entity\ContentArea;
use ContentBlocks\Entity\Section;
use ContentBlocks\Security\AccessCheckerInterface;
use ContentBlocks\Security\ContentBlocksAccessDeniedException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Response;

final class ColumnsControllerTest extends ControllerTestCase
{
    private function makeController(
        EntityManagerInterface $em,
        bool $csrfValid = true,
        ?AccessCheckerInterface $accessChecker = null,
    ): ColumnsController {
        return new ColumnsController(
            $em,
            $accessChecker ?? $this->makeAccessChecker(),
            $this->makeCsrfManager($csrfValid),
            $this->makeJournal($em),
        );
    }

    /** @return array{ContentArea, Section, list<Column>} */
    private function makeTwoColumnSection(): array
    {
        $area = $this->makeArea(1);
        $section = $this->makeSection($area, 10);
        $columns = [$this->makeColumn($section, 100, 0), $this->makeColumn($section, 101, 1)];
        foreach ($columns as $column) {
            $column->setPreset('col-6');
        }

        return [$area, $section, $columns];
    }

    // ---------- create ----------

    public function testCreateAppendsAColumnAndRespansThemAll(): void
    {
        [$area, $section] = $this->makeTwoColumnSection();
        $controller = $this->makeController($this->makeEm([$area, $section]));

        $response = $controller->create(10, $this->makeJsonRequest());

        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
        $this->assertSame(3, json_decode((string) $response->getContent(), true)['columnCount']);
        $columns = $section->getColumns()->toArray();
        $this->assertCount(3, $columns);
        $this->assertSame(['col-4', 'col-4', 'col-4'], array_map(fn (Column $c) => $c->getPreset(), $columns));
        $this->assertSame(2, $columns[2]->getPreviewPosition());
        $this->assertSame([$columns[2]], $this->persisted);
        $this->assertSame(1, $this->flushCount);
    }

    /** Past twelve, a span floors at one: the grid shares out equally. */
    public function testASpanNeverDropsBelowOne(): void
    {
        $area = $this->makeArea(1);
        $section = $this->makeSection($area, 10);
        for ($i = 0; $i < 12; ++$i) {
            $this->makeColumn($section, 100 + $i, $i)->setPreset('col-1');
        }
        $controller = $this->makeController($this->makeEm([$area, $section]));

        $controller->create(10, $this->makeJsonRequest());

        $this->assertSame('col-1', $section->getColumns()->last()->getPreset());
    }

    public function testCreateRefusesPastTheCap(): void
    {
        $area = $this->makeArea(1);
        $section = $this->makeSection($area, 10);
        for ($i = 0; $i < ColumnsController::MAX_COLUMNS; ++$i) {
            $this->makeColumn($section, 100 + $i, $i);
        }
        $controller = $this->makeController($this->makeEm([$area, $section]));

        $response = $controller->create(10, $this->makeJsonRequest());

        $this->assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
        $this->assertSame('too_many_columns', json_decode((string) $response->getContent(), true)['error']);
        $this->assertSame(0, $this->flushCount);
    }

    /** A deleted column does not count against the cap. */
    public function testDeletedColumnsDoNotCountTowardsTheCap(): void
    {
        $area = $this->makeArea(1);
        $section = $this->makeSection($area, 10);
        for ($i = 0; $i < ColumnsController::MAX_COLUMNS; ++$i) {
            $this->makeColumn($section, 100 + $i, $i)->setDeleted($i === 0);
        }
        $controller = $this->makeController($this->makeEm([$area, $section]));

        $response = $controller->create(10, $this->makeJsonRequest());

        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
    }

    public function testCreateRejectsInvalidCsrf(): void
    {
        [$area, $section] = $this->makeTwoColumnSection();
        $controller = $this->makeController($this->makeEm([$area, $section]), csrfValid: false);

        $this->assertSame(Response::HTTP_FORBIDDEN, $controller->create(10, $this->makeJsonRequest())->getStatusCode());
        $this->assertCount(2, $section->getColumns());
    }

    public function testCreateReturns404ForAnUnknownSection(): void
    {
        $controller = $this->makeController($this->makeEm());

        $this->assertSame(Response::HTTP_NOT_FOUND, $controller->create(99, $this->makeJsonRequest())->getStatusCode());
    }

    public function testCreateChecksEditAccess(): void
    {
        [$area, $section] = $this->makeTwoColumnSection();
        $denied = $this->createMock(AccessCheckerInterface::class);
        $denied->method('canEdit')->willReturn(false);
        $controller = $this->makeController($this->makeEm([$area, $section]), accessChecker: $denied);

        $this->expectException(ContentBlocksAccessDeniedException::class);

        $controller->create(10, $this->makeJsonRequest());
    }

    // ---------- delete ----------

    /** A flag, not a removal: the public page keeps it until Publish. */
    public function testDeleteFlagsTheColumnAndRespansTheRest(): void
    {
        [$area, $section, $columns] = $this->makeTwoColumnSection();
        $third = $this->makeColumn($section, 102, 2);
        $third->setPreset('col-4');
        $columns[0]->setPreset('col-4');
        $columns[1]->setPreset('col-4');
        $block = $this->makeBlock($columns[1], 500);
        $controller = $this->makeController($this->makeEm([$area, $section, ...$columns, $third]));

        $response = $controller->delete(101, $this->makeJsonRequest());

        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
        $this->assertTrue($columns[1]->isDeleted());
        $this->assertCount(3, $section->getColumns());
        // Its blocks stay with it, so Discard or undo bring them back whole.
        $this->assertSame($columns[1], $block->getColumn());
        $this->assertFalse($block->isDeleted());
        $this->assertSame('col-6', $columns[0]->getPreset());
        $this->assertSame('col-6', $third->getPreset());
        $this->assertSame('col-4', $columns[1]->getPreset());
    }

    public function testDeleteRefusesTheLastLiveColumn(): void
    {
        [$area, $section, $columns] = $this->makeTwoColumnSection();
        $columns[0]->setDeleted(true);
        $controller = $this->makeController($this->makeEm([$area, $section, ...$columns]));

        $response = $controller->delete(101, $this->makeJsonRequest());

        $this->assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
        $this->assertSame('last_column', json_decode((string) $response->getContent(), true)['error']);
        $this->assertFalse($columns[1]->isDeleted());
    }

    public function testDeletingAnAlreadyDeletedColumnIsANoOp(): void
    {
        [$area, $section, $columns] = $this->makeTwoColumnSection();
        $columns[0]->setDeleted(true);
        $controller = $this->makeController($this->makeEm([$area, $section, ...$columns]));

        $response = $controller->delete(100, $this->makeJsonRequest());

        $this->assertFalse(json_decode((string) $response->getContent(), true)['deleted']);
        $this->assertSame(0, $this->flushCount);
    }

    public function testDeleteReturns404ForAnUnknownColumn(): void
    {
        $controller = $this->makeController($this->makeEm());

        $this->assertSame(Response::HTTP_NOT_FOUND, $controller->delete(9, $this->makeJsonRequest())->getStatusCode());
    }

    public function testDeleteRejectsInvalidCsrf(): void
    {
        [$area, $section, $columns] = $this->makeTwoColumnSection();
        $controller = $this->makeController($this->makeEm([$area, $section, ...$columns]), csrfValid: false);

        $this->assertSame(Response::HTTP_FORBIDDEN, $controller->delete(100, $this->makeJsonRequest())->getStatusCode());
        $this->assertFalse($columns[0]->isDeleted());
    }

    // ---------- settings ----------

    public function testSettingsWriteASanitizedLabelToTheDraft(): void
    {
        [$area, $section, $columns] = $this->makeTwoColumnSection();
        $columns[0]->publish();
        $controller = $this->makeController($this->makeEm([$area, $section, ...$columns]));

        $response = $controller->settings(100, $this->makeJsonRequest(['label' => '  Specs ', 'evil' => 1]));

        $this->assertSame(Response::HTTP_NO_CONTENT, $response->getStatusCode());
        $this->assertSame(['label' => 'Specs'], $columns[0]->getDraftSettings());
        $this->assertNull($columns[0]->getPublishedSettings());
    }

    /** Clearing the name is a draft change too, not a return to published. */
    public function testAnEmptyLabelClearsItInTheDraft(): void
    {
        [$area, $section, $columns] = $this->makeTwoColumnSection();
        $columns[0]->setPublishedSettings(['label' => 'Live']);
        $controller = $this->makeController($this->makeEm([$area, $section, ...$columns]));

        $controller->settings(100, $this->makeJsonRequest(['label' => '']));

        $this->assertSame([], $columns[0]->getDraftSettings());
        $this->assertSame(['label' => 'Live'], $columns[0]->getPublishedSettings());
    }

    public function testSettingsRejectANonObjectPayload(): void
    {
        [$area, $section, $columns] = $this->makeTwoColumnSection();
        $controller = $this->makeController($this->makeEm([$area, $section, ...$columns]));

        $request = $this->makeJsonRequest();
        $request->initialize([], [], [], [], [], $request->server->all(), '"label"');

        $this->assertSame(Response::HTTP_BAD_REQUEST, $controller->settings(100, $request)->getStatusCode());
    }

    public function testSettingsChecksEditAccess(): void
    {
        [$area, $section, $columns] = $this->makeTwoColumnSection();
        $denied = $this->createMock(AccessCheckerInterface::class);
        $denied->method('canEdit')->willReturn(false);
        $controller = $this->makeController($this->makeEm([$area, $section, ...$columns]), accessChecker: $denied);

        $this->expectException(ContentBlocksAccessDeniedException::class);

        $controller->settings(100, $this->makeJsonRequest(['label' => 'x']));
    }
}
