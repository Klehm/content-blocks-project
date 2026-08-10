<?php

declare(strict_types=1);

namespace ContentBlocks\Tests\Controller;

use ContentBlocks\Controller\SectionTemplateController;
use ContentBlocks\Entity\SectionTemplate;
use ContentBlocks\Security\AccessCheckerInterface;
use ContentBlocks\Section\SectionStyleRegistry;
use ContentBlocks\Security\ContentBlocksAccessDeniedException;
use ContentBlocks\Tests\Fixtures\EchoTranslator;
use ContentBlocks\SectionTemplate\AllowAllSectionTemplateManager;
use ContentBlocks\SectionTemplate\DenyAllSectionTemplateManager;
use ContentBlocks\SectionTemplate\SectionPosterBuilder;
use ContentBlocks\SectionTemplate\SectionTemplateManagerInterface;
use ContentBlocks\SectionTemplate\SectionTemplateInstantiator;
use ContentBlocks\SectionTemplate\SectionTemplateSerializer;
use ContentBlocks\Versioning\ContentVersionUpgraderInterface;
use ContentBlocks\Versioning\DenyOnMismatchUpgrader;
use ContentBlocks\Versioning\EnvelopeUpgradeChain;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * Unit tests for the section-template library flow. list() is exercised only
 * for its guards: its happy path drives a Doctrine QueryBuilder/Query pair
 * (Query is final) that cannot be meaningfully doubled — that path is covered
 * by the Playwright suite against the real sandbox.
 */
final class SectionTemplateControllerTest extends ControllerTestCase
{
    private function makeController(
        EntityManagerInterface $em,
        bool $csrfValid = true,
        ?AccessCheckerInterface $accessChecker = null,
        ?SectionTemplateManagerInterface $manager = null,
        ?ContentVersionUpgraderInterface $upgrader = null,
    ): SectionTemplateController {
        return new SectionTemplateController(
            $em,
            $accessChecker ?? $this->makeAccessChecker(),
            $manager ?? new AllowAllSectionTemplateManager(),
            new SectionTemplateSerializer(),
            new SectionTemplateInstantiator($this->makeRegistry(), $this->makeDataKeys()),
            $this->makeRegistry(),
            new SectionPosterBuilder($this->makeRegistry(), new EchoTranslator(), new SectionStyleRegistry()),
            $this->makeCsrfManager($csrfValid),
            $upgrader ?? new DenyOnMismatchUpgrader(),
            new EnvelopeUpgradeChain(),
            5,
        );
    }

    private function makeTemplate(int $id, array $payload, array $blockTypes, string $name = 'Hero'): SectionTemplate
    {
        $template = (new SectionTemplate())
            ->setName($name)
            ->setPayload($payload)
            ->setBlockTypes($blockTypes);
        $this->setEntityId($template, $id);

        return $template;
    }

    /** @param list<array<string, mixed>> $blocks */
    private function payloadWith(array $blocks): array
    {
        return [
            'format' => SectionTemplateSerializer::FORMAT,
            'layout' => 'full',
            'settings' => null,
            'columns' => [['preset' => 'col-12', 'blocks' => $blocks]],
        ];
    }

    // ---------- save ----------

    public function testSaveSnapshotsSectionIntoTheLibrary(): void
    {
        $area = $this->makeArea(1);
        $section = $this->makeSection($area, 10, layout: 'two_cols');
        $column = $this->makeColumn($section, 11);
        $this->makeBlock($column, 12)->setDraftData(['content' => 'hello']);

        $controller = $this->makeController($this->makeEm([$section]));

        $response = $controller->save(10, $this->makeJsonRequest(['name' => '  My hero  ']));

        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
        $this->assertCount(1, $this->persisted);
        $template = $this->persisted[0];
        $this->assertInstanceOf(SectionTemplate::class, $template);
        $this->assertSame('My hero', $template->getName(), 'name is trimmed');
        $this->assertSame(['fake'], $template->getBlockTypes());
        $this->assertSame('two_cols', $template->getPayload()['layout']);
        // A snapshot is frozen, so its stamp keeps describing its payload.
        $this->assertSame(5, $template->getContentVersion());
        $this->assertSame(1, $this->flushCount);
    }

    public function testSaveRejectsBlankName(): void
    {
        $area = $this->makeArea(1);
        $section = $this->makeSection($area, 10);
        $controller = $this->makeController($this->makeEm([$section]));

        $response = $controller->save(10, $this->makeJsonRequest(['name' => '   ']));

        $this->assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
        $this->assertCount(0, $this->persisted);
    }

    public function testSaveReturns404ForUnknownSection(): void
    {
        $controller = $this->makeController($this->makeEm());

        $response = $controller->save(99, $this->makeJsonRequest(['name' => 'x']));

        $this->assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
    }

    public function testSaveRejectsInvalidCsrf(): void
    {
        $controller = $this->makeController($this->makeEm(), csrfValid: false);

        $response = $controller->save(10, $this->makeJsonRequest(['name' => 'x']));

        $this->assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
    }

    public function testSaveDeniedWhenAreaNotEditable(): void
    {
        $area = $this->makeArea(1);
        $section = $this->makeSection($area, 10);
        $denier = $this->createMock(AccessCheckerInterface::class);
        $denier->method('canEdit')->willReturn(false);
        $controller = $this->makeController($this->makeEm([$section]), accessChecker: $denier);

        $this->expectException(ContentBlocksAccessDeniedException::class);
        $controller->save(10, $this->makeJsonRequest(['name' => 'x']));
    }

    // ---------- insert ----------

    public function testInsertAppendsSectionAsDraftAtEnd(): void
    {
        $area = $this->makeArea(1);
        $this->makeSection($area, 5, previewPosition: 3); // existing section
        $template = $this->makeTemplate(7, $this->payloadWith([
            ['type' => 'fake', 'data' => ['content' => 'x']],
        ]), ['fake']);

        $controller = $this->makeController($this->makeEm([$area, $template]));

        $response = $controller->insert(1, 7, $this->makeJsonRequest());

        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
        $payload = json_decode((string) $response->getContent(), true);
        $this->assertSame(0, $payload['skippedBlockCount']);
        $this->assertSame([], $payload['unknownFields']);
        $this->assertCount(1, $this->persisted);
        // Appended after the existing section (previewPosition 3 -> 4).
        $this->assertSame(4, $this->persisted[0]->getPreviewPosition());
        $this->assertCount(2, $area->getSections());
    }

    public function testInsertSkipsGoneBlockTypesAndInsertsTheRest(): void
    {
        $area = $this->makeArea(1);
        $template = $this->makeTemplate(7, $this->payloadWith([
            ['type' => 'fake', 'data' => []],
            ['type' => 'ghost', 'data' => []],
        ]), ['fake', 'ghost']);

        $controller = $this->makeController($this->makeEm([$area, $template]));

        $response = $controller->insert(1, 7, $this->makeJsonRequest());

        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
        $payload = json_decode((string) $response->getContent(), true);
        $this->assertSame(1, $payload['skippedBlockCount']);
        $this->assertSame(['ghost'], $payload['skippedBlockTypes']);
        $this->assertCount(1, $this->persisted);
    }

    public function testInsertRejectsAStaleContentVersionWith422(): void
    {
        $area = $this->makeArea(1);
        $template = $this->makeTemplate(7, $this->payloadWith([
            ['type' => 'fake', 'data' => ['content' => 'x']],
        ]), ['fake']);
        // Controller runs generation 5; this snapshot was taken under 3.
        $template->setContentVersion(3);

        $controller = $this->makeController($this->makeEm([$area, $template]));

        $response = $controller->insert(1, 7, $this->makeJsonRequest());

        $this->assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());
        $payload = json_decode((string) $response->getContent(), true);
        $this->assertSame('incompatible_content_version', $payload['error']);
        $this->assertSame(3, $payload['storedVersion']);
        $this->assertSame(5, $payload['currentVersion']);
        $this->assertCount(0, $this->persisted);
    }

    public function testAHostUpgraderCanMigrateThePayloadOnRead(): void
    {
        // The whole point of the seam: the host knows what changed between its
        // own generations, so it can rewrite the payload on the way in. The
        // rewrite is transient — the stored row is untouched.
        $area = $this->makeArea(1);
        $stored = $this->payloadWith([['type' => 'fake', 'data' => ['legacyContent' => 'hello']]]);
        $template = $this->makeTemplate(7, $stored, ['fake']);
        $template->setContentVersion(3);

        $upgrader = new class implements ContentVersionUpgraderInterface {
            public function supports(?int $stored, int $current): bool
            {
                return true;
            }

            public function upgrade(array $payload, ?int $stored, int $current): array
            {
                foreach ($payload['columns'] as $c => $column) {
                    foreach ($column['blocks'] as $b => $block) {
                        $data = $block['data'];
                        if (isset($data['legacyContent'])) {
                            $data['content'] = $data['legacyContent'];
                            unset($data['legacyContent']);
                        }
                        $payload['columns'][$c]['blocks'][$b]['data'] = $data;
                    }
                }

                return $payload;
            }
        };

        $controller = $this->makeController($this->makeEm([$area, $template]), upgrader: $upgrader);

        $response = $controller->insert(1, 7, $this->makeJsonRequest());

        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
        $inserted = $this->persisted[0];
        $block = $inserted->getColumns()->first()->getBlocks()->first();
        $this->assertSame(['content' => 'hello'], $block->getDraftData(), 'the upgraded payload was instantiated');
        $this->assertSame(
            ['legacyContent' => 'hello'],
            $template->getPayload()['columns'][0]['blocks'][0]['data'],
            'the stored row is untouched — a permanent rewrite is a migration',
        );
    }

    public function testInsertRejectsATemplateWhoseBlocksAreAllGoneWith422(): void
    {
        $area = $this->makeArea(1);
        $template = $this->makeTemplate(7, $this->payloadWith([
            ['type' => 'ghost', 'data' => []],
        ]), ['ghost']);

        $controller = $this->makeController($this->makeEm([$area, $template]));

        $response = $controller->insert(1, 7, $this->makeJsonRequest());

        $this->assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());
        $payload = json_decode((string) $response->getContent(), true);
        $this->assertSame('incompatible_template', $payload['error']);
        $this->assertSame(['ghost'], $payload['missingTypes']);
        $this->assertCount(0, $this->persisted);
    }

    public function testInsertReportsUnknownFieldWarningsButStillInserts(): void
    {
        $area = $this->makeArea(1);
        // FakeBlockType::getDefaultData() only declares `content`; `legacy` is stale.
        $template = $this->makeTemplate(7, $this->payloadWith([
            ['type' => 'fake', 'data' => ['content' => 'ok', 'legacy' => 'v']],
        ]), ['fake']);

        $controller = $this->makeController($this->makeEm([$area, $template]));

        $response = $controller->insert(1, 7, $this->makeJsonRequest());

        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
        $payload = json_decode((string) $response->getContent(), true);
        $this->assertSame(
            [['blockType' => 'fake', 'unknownKeys' => ['legacy']]],
            $payload['unknownFields'],
        );
        $this->assertCount(1, $this->persisted);
    }

    public function testInsertReturns404ForUnknownAreaOrTemplate(): void
    {
        $area = $this->makeArea(1);
        $template = $this->makeTemplate(7, $this->payloadWith([]), []);
        $controller = $this->makeController($this->makeEm([$area, $template]));

        $this->assertSame(
            Response::HTTP_NOT_FOUND,
            $controller->insert(9, 7, $this->makeJsonRequest())->getStatusCode(),
        );
        $this->assertSame(
            Response::HTTP_NOT_FOUND,
            $controller->insert(1, 9, $this->makeJsonRequest())->getStatusCode(),
        );
    }

    // ---------- delete / rename (management gate) ----------

    public function testDeleteRemovesTemplateWhenManagementAllowed(): void
    {
        $template = $this->makeTemplate(7, $this->payloadWith([]), []);
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('find')->willReturn($template);
        $removed = [];
        $em->method('remove')->willReturnCallback(function (object $e) use (&$removed): void {
            $removed[] = $e;
        });
        $controller = $this->makeController($em);

        $response = $controller->delete(7, $this->makeJsonRequest());

        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
        $this->assertSame([$template], $removed);
    }

    public function testDeleteDeniedWhenManagementForbidden(): void
    {
        $template = $this->makeTemplate(7, $this->payloadWith([]), []);
        $controller = $this->makeController(
            $this->makeEm([$template]),
            manager: new DenyAllSectionTemplateManager(),
        );

        $this->expectException(ContentBlocksAccessDeniedException::class);
        $controller->delete(7, $this->makeJsonRequest());
    }

    public function testRenameUpdatesNameWhenAllowed(): void
    {
        $template = $this->makeTemplate(7, $this->payloadWith([]), [], name: 'Old');
        $controller = $this->makeController($this->makeEm([$template]));

        $response = $controller->rename(7, $this->makeJsonRequest(['name' => 'New name']));

        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
        $this->assertSame('New name', $template->getName());
    }

    public function testRenameDeniedWhenManagementForbidden(): void
    {
        $template = $this->makeTemplate(7, $this->payloadWith([]), []);
        $controller = $this->makeController(
            $this->makeEm([$template]),
            manager: new DenyAllSectionTemplateManager(),
        );

        $this->expectException(ContentBlocksAccessDeniedException::class);
        $controller->rename(7, $this->makeJsonRequest(['name' => 'x']));
    }

    // ---------- list (guards only) ----------

    public function testInsertRejectsAnUnreadableEnvelopeWith422(): void
    {
        $area = $this->makeArea(1);
        $payload = $this->payloadWith([['type' => 'fake', 'data' => []]]);
        $payload['format'] = 'content-blocks/section-v99';
        $template = $this->makeTemplate(7, $payload, ['fake']);

        $controller = $this->makeController($this->makeEm([$area, $template]));

        $response = $controller->insert(1, 7, $this->makeJsonRequest());

        $this->assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        $this->assertSame('unsupported_template_format', $body['error']);
        $this->assertSame('content-blocks/section-v99', $body['found']);
        $this->assertCount(0, $this->persisted);
    }

    public function testListReturns404ForUnknownArea(): void
    {
        $controller = $this->makeController($this->makeEm());

        $response = $controller->list(9, $this->makeJsonRequest());

        $this->assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
    }

    public function testListDeniesAccessWhenCheckerRefuses(): void
    {
        $area = $this->makeArea(1);
        $denier = $this->createMock(AccessCheckerInterface::class);
        $denier->method('canEdit')->willReturn(false);
        $controller = $this->makeController($this->makeEm([$area]), accessChecker: $denier);

        $this->expectException(ContentBlocksAccessDeniedException::class);
        $controller->list(1, $this->makeJsonRequest());
    }
}
