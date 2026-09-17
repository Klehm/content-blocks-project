<?php

declare(strict_types=1);

namespace ContentBlocks\Tests\Controller;

use ContentBlocks\Controller\SectionsController;
use ContentBlocks\Entity\Section;
use ContentBlocks\Rendering\BlockRendererInterface;
use ContentBlocks\Rendering\RenderContext;
use ContentBlocks\Rendering\RenderMode;
use ContentBlocks\Section\SectionCloner;
use ContentBlocks\Section\SectionLayoutRegistry;
use ContentBlocks\Security\AccessCheckerInterface;
use ContentBlocks\Security\ContentBlocksAccessDeniedException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Response;

final class SectionsControllerTest extends ControllerTestCase
{
    /** @param array<string, mixed> $initialSettings */
    private function makeController(
        EntityManagerInterface $em,
        bool $csrfValid = true,
        ?AccessCheckerInterface $accessChecker = null,
        ?BlockRendererInterface $renderer = null,
        array $initialSettings = [],
        ?SectionLayoutRegistry $layouts = null,
    ): SectionsController {
        return new SectionsController(
            $em,
            $accessChecker ?? $this->makeAccessChecker(),
            $this->makeCsrfManager($csrfValid),
            new SectionCloner(),
            $renderer ?? $this->makeUnusedRenderer(),
            $this->makeRegistry(),
            $this->makeJournal($em),
            $initialSettings,
            $layouts ?? new SectionLayoutRegistry(),
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

    public function testCreateLeavesSettingsEmptyWithNoInitialSettings(): void
    {
        $area = $this->makeArea(1);
        $controller = $this->makeController($this->makeEm([$area]));

        $controller->create(1, $this->makeJsonRequest(['layout' => Section::LAYOUT_FULL]));

        /** @var Section $section */
        $section = $this->persisted[0];
        $this->assertNull($section->getDraftSettings());
        $this->assertNull($section->getPublishedSettings());
    }

    /**
     * The configured settings are the new section's own draft values, so the
     * render and the sidebar read them like anything an editor saved.
     */
    public function testCreateWritesTheInitialSettingsToTheDraft(): void
    {
        $settings = [
            'widthMode' => 'centered',
            'stylingCustom' => true,
            'styling' => ['padding' => ['desktop' => ['top' => 12, 'bottom' => 12]]],
        ];
        $area = $this->makeArea(1);
        $controller = $this->makeController($this->makeEm([$area]), initialSettings: $settings);

        $controller->create(1, $this->makeJsonRequest(['layout' => Section::LAYOUT_TWO_COLS]));

        /** @var Section $section */
        $section = $this->persisted[0];
        $this->assertSame($settings, $section->getDraftSettings());
        // Draft only: nothing reaches the published page before Publish.
        $this->assertNull($section->getPublishedSettings());
    }

    public function testCreateShipsThePreviewMarkupForAnInPlaceInsert(): void
    {
        $area = $this->makeArea(1);
        $renderer = $this->createMock(BlockRendererInterface::class);
        $renderer->expects($this->once())
            ->method('renderSection')
            ->with(
                $this->isInstanceOf(Section::class),
                $this->callback(fn (RenderContext $c) => $c->mode === RenderMode::PREVIEW),
            )
            ->willReturn('<section data-cb-section-id="7"></section>');
        $controller = $this->makeController($this->makeEm([$area]), renderer: $renderer);

        $response = $controller->create(1, $this->makeJsonRequest(['layout' => Section::LAYOUT_FULL]));

        $payload = json_decode((string) $response->getContent(), true);
        // A new section has no block, so it always qualifies.
        $this->assertTrue($payload['hotReload']);
        $this->assertSame('<section data-cb-section-id="7"></section>', $payload['html']);
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

    public function testCreateBuildsAHostLayoutFromItsSpans(): void
    {
        $area = $this->makeArea(1);
        $layouts = new SectionLayoutRegistry(SectionLayoutRegistry::resolve([
            'four_cols' => ['label' => '4 columns', 'columns' => [3, 3, 3, 3]],
        ]));
        $controller = $this->makeController($this->makeEm([$area]), layouts: $layouts);

        $response = $controller->create(1, $this->makeJsonRequest(['layout' => 'four_cols']));

        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
        /** @var Section $section */
        $section = $this->persisted[0];
        $this->assertSame('four_cols', $section->getLayout());
        $presets = [];
        foreach ($section->getColumns() as $column) {
            $presets[] = [$column->getPreset(), $column->getPreviewPosition()];
        }
        $this->assertSame([['col-3', 0], ['col-3', 1], ['col-3', 2], ['col-3', 3]], $presets);
    }

    public function testATabsLayoutStartsItsSectionAsTabsOverTheInitialSettings(): void
    {
        $area = $this->makeArea(1);
        $layouts = new SectionLayoutRegistry(SectionLayoutRegistry::resolve([
            'tabs' => ['label' => 'Tabs', 'columns' => [6, 6], 'display' => 'tabs'],
        ]));
        $controller = $this->makeController(
            $this->makeEm([$area]),
            initialSettings: ['widthMode' => 'centered'],
            layouts: $layouts,
        );

        $controller->create(1, $this->makeJsonRequest(['layout' => 'tabs']));

        /** @var Section $section */
        $section = $this->persisted[0];
        $this->assertSame(['widthMode' => 'centered', 'display' => 'tabs'], $section->getDraftSettings());
    }

    /** Hidden from the buttons means refused from a forged POST too. */
    public function testCreateRejectsADisabledLayout(): void
    {
        $area = $this->makeArea(1);
        $layouts = new SectionLayoutRegistry(SectionLayoutRegistry::resolve([
            'three_cols' => ['enabled' => false],
        ]));
        $controller = $this->makeController($this->makeEm([$area]), layouts: $layouts);

        $response = $controller->create(1, $this->makeJsonRequest(['layout' => Section::LAYOUT_THREE_COLS]));

        $this->assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
        $this->assertSame(0, $this->flushCount);
    }

    public function testCreateRejectsANonStringLayout(): void
    {
        $area = $this->makeArea(1);
        $controller = $this->makeController($this->makeEm([$area]));

        $response = $controller->create(1, $this->makeJsonRequest(['layout' => ['full']]));

        $this->assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
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
