<?php

declare(strict_types=1);

namespace ContentBlocks\Tests\Controller;

use ContentBlocks\Builder\BuilderStructure;
use ContentBlocks\Content\ContentManipulator;
use ContentBlocks\Controller\BlocksController;
use ContentBlocks\Controller\ColumnsController;
use ContentBlocks\Controller\SectionsController;
use ContentBlocks\Entity\Block;
use ContentBlocks\Entity\Section;
use ContentBlocks\Section\SectionCloner;
use ContentBlocks\Tests\Fixtures\FixedBuilderStructureResolver;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * What `content_blocks.structure` rules out is refused by the endpoints, not
 * only hidden by the UI, and what it leaves open still works.
 */
final class BuilderStructureEnforcementTest extends ControllerTestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function lockedSections(): iterable
    {
        yield 'fixed' => [BuilderStructure::SECTIONS_FIXED];
        yield 'hidden' => [BuilderStructure::SECTIONS_HIDDEN];
    }

    #[DataProvider('lockedSections')]
    public function testEverySectionChangeIsRefused(string $mode): void
    {
        $area = $this->makeArea(1);
        $section = $this->makeSection($area, 10);
        $this->makeSection($area, 11, 1);
        $controller = $this->sections($this->makeEm([$area, $section]), new BuilderStructure($mode));

        $responses = [
            'create' => $controller->create(1, $this->makeJsonRequest(['layout' => Section::LAYOUT_FULL])),
            'move' => $controller->move(10, $this->makeJsonRequest(['direction' => 'down'])),
            'duplicate' => $controller->duplicate(10, $this->makeJsonRequest()),
            'delete' => $controller->delete(10, $this->makeJsonRequest()),
            'restore' => $controller->restore(10, $this->makeJsonRequest()),
        ];

        foreach ($responses as $action => $response) {
            $this->assertRefused($response, $action);
        }
        $this->assertSame(0, $this->flushCount, 'nothing was written');
        $this->assertFalse($section->isDeleted());
    }

    public function testEditableSectionsAreStillChanged(): void
    {
        $area = $this->makeArea(1);
        $section = $this->makeSection($area, 10);

        $response = $this->sections($this->makeEm([$area, $section]), new BuilderStructure())
            ->delete(10, $this->makeJsonRequest());

        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
        $this->assertTrue($section->isDeleted());
    }

    public function testLockedColumnsRefuseEveryChange(): void
    {
        $area = $this->makeArea(1);
        $section = $this->makeSection($area, 10, 0, Section::LAYOUT_TWO_COLS);
        $first = $this->makeColumn($section, 100, 0);
        $this->makeColumn($section, 101, 1);
        $controller = $this->columns($this->makeEm([$area, $section, $first]), new BuilderStructure(columns: false));

        $this->assertRefused($controller->create(10, $this->makeJsonRequest()), 'create');
        $this->assertRefused($controller->delete(100, $this->makeJsonRequest()), 'delete');
        $this->assertRefused($controller->settings(100, $this->makeJsonRequest(['label' => 'Tab'])), 'settings');
        $this->assertSame(0, $this->flushCount);
        $this->assertFalse($first->isDeleted());
    }

    // Hidden sections have no sidebar, so no column to edit either.
    public function testHiddenSectionsLockTheColumnsToo(): void
    {
        $area = $this->makeArea(1);
        $section = $this->makeSection($area, 10);

        $response = $this->columns($this->makeEm([$area, $section]), new BuilderStructure(BuilderStructure::SECTIONS_HIDDEN))
            ->create(10, $this->makeJsonRequest());

        $this->assertRefused($response, 'create');
    }

    // ---------- POST /area/{id}/blocks ----------

    public function testAnAppendedBlockLandsAtTheEndOfTheLastSection(): void
    {
        $area = $this->makeArea(1);
        $first = $this->makeSection($area, 10, 0);
        $this->makeColumn($first, 100);
        $last = $this->makeSection($area, 11, 1, Section::LAYOUT_TWO_COLS);
        $lastFirstColumn = $this->makeColumn($last, 101, 0);
        $this->makeColumn($last, 102, 1);
        $existing = $this->makeBlock($lastFirstColumn, 1000);

        $response = $this->blocks($this->makeEm([$area]), new BuilderStructure(BuilderStructure::SECTIONS_HIDDEN))
            ->createInArea(1, $this->makeJsonRequest(['type' => FakeBlockType::TYPE]));

        $this->assertSame(Response::HTTP_OK, $response->getStatusCode(), (string) $response->getContent());
        $block = $this->persistedBlock();
        $this->assertSame($lastFirstColumn, $block->getColumn());
        $this->assertSame(1, $block->getPreviewPosition());
        $this->assertSame(0, $existing->getPreviewPosition());
    }

    public function testAnEmptyAreaGetsTheSectionItsFirstBlockNeeds(): void
    {
        $area = $this->makeArea(1);

        $response = $this->blocks($this->makeEm([$area]), new BuilderStructure(BuilderStructure::SECTIONS_HIDDEN))
            ->createInArea(1, $this->makeJsonRequest(['type' => FakeBlockType::TYPE]));

        $this->assertSame(Response::HTTP_OK, $response->getStatusCode(), (string) $response->getContent());
        $section = $this->persistedBlock()->getColumn()?->getSection();
        $this->assertNotNull($section);
        $this->assertSame($area, $section->getContentArea());
        $this->assertSame(Section::LAYOUT_FULL, $section->getLayout());
        $this->assertFalse(json_decode((string) $response->getContent(), true)['hotReload']);
    }

    // Fixed sections are the host's: an empty area has nowhere to go.
    public function testAFixedEmptyAreaHasNoTarget(): void
    {
        $area = $this->makeArea(1);

        $response = $this->blocks($this->makeEm([$area]), new BuilderStructure(BuilderStructure::SECTIONS_FIXED))
            ->createInArea(1, $this->makeJsonRequest(['type' => FakeBlockType::TYPE]));

        $this->assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());
        $this->assertSame('no_target', json_decode((string) $response->getContent(), true)['error']);
        $this->assertSame([], $this->persisted);
    }

    public function testAnUnknownTypeIsNotAppended(): void
    {
        $area = $this->makeArea(1);

        $response = $this->blocks($this->makeEm([$area]), new BuilderStructure())
            ->createInArea(1, $this->makeJsonRequest(['type' => 'nope']));

        $this->assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
        $this->assertSame([], $this->persisted);
    }

    // ---------- plumbing ----------

    private function assertRefused(Response $response, string $action): void
    {
        $this->assertSame(Response::HTTP_CONFLICT, $response->getStatusCode(), $action);
        $this->assertInstanceOf(JsonResponse::class, $response);
        $body = json_decode((string) $response->getContent(), true);
        $this->assertSame('refused', $body['error'], $action);
        $this->assertSame(['structure'], $body['reasons'], $action);
    }

    private function persistedBlock(): Block
    {
        foreach ($this->persisted as $entity) {
            if ($entity instanceof Block) {
                return $entity;
            }
        }
        $this->fail('no block was persisted');
    }

    private function sections(EntityManagerInterface $em, BuilderStructure $structure): SectionsController
    {
        return new SectionsController(
            $em,
            $this->makeAccessChecker(),
            $this->makeCsrfManager(),
            new SectionCloner(),
            $this->makeUnusedRenderer(),
            $this->makeRegistry(),
            $this->makeJournal($em),
            structure: new FixedBuilderStructureResolver($structure),
        );
    }

    private function columns(EntityManagerInterface $em, BuilderStructure $structure): ColumnsController
    {
        return new ColumnsController(
            $em,
            $this->makeAccessChecker(),
            $this->makeCsrfManager(),
            $this->makeJournal($em),
            structure: new FixedBuilderStructureResolver($structure),
        );
    }

    private function blocks(EntityManagerInterface $em, BuilderStructure $structure): BlocksController
    {
        $registry = $this->makeRegistry();

        return new BlocksController(
            $em,
            $this->makeAccessChecker(),
            $registry,
            $this->makeCsrfManager(),
            $this->createMock(TranslatorInterface::class),
            $this->makeUnusedRenderer(),
            $this->makeJournal($em),
            content: new ContentManipulator($em, $registry),
            structure: new FixedBuilderStructureResolver($structure),
        );
    }
}
