<?php

declare(strict_types=1);

namespace ContentBlocks\Tests\Controller;

use ContentBlocks\Block\BlockDataDefaults;
use ContentBlocks\Block\BlockDataKeys;
use ContentBlocks\Block\CollectionItemIds;
use ContentBlocks\BlockType\AbstractBlockType;
use ContentBlocks\BlockType\BlockTypeRegistry;
use ContentBlocks\Clipboard\BlockDataReplayer;
use ContentBlocks\Clipboard\BlockSnapshotSerializer;
use ContentBlocks\Clipboard\BlockSnapshotSerializerInterface;
use ContentBlocks\Clipboard\ClipboardEnvelope;
use ContentBlocks\Clipboard\ClipboardPaster;
use ContentBlocks\Controller\ClipboardController;
use ContentBlocks\Entity\Block;
use ContentBlocks\Entity\Section;
use ContentBlocks\Form\Extension\BlockFormExtensionCollection;
use ContentBlocks\Form\Type\BlockFormType;
use ContentBlocks\Security\ContentBlocksAccessDeniedException;
use ContentBlocks\Security\DenyAllAccessChecker;
use ContentBlocks\SectionTemplate\SectionTemplateInstantiator;
use ContentBlocks\SectionTemplate\SectionTemplateSerializer;
use ContentBlocks\SectionTemplate\SectionTemplateSerializerInterface;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Validator\ValidatorExtension;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\Forms;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Validation;

/**
 * Copy is a read; paste is the interesting half — it takes a payload the
 * browser handed back and has to answer two questions before writing anything:
 * *may this land here* (the target area's canEdit, the content generation) and
 * *where* (the selection). Both are pinned here, alongside the tampering cases.
 */
final class ClipboardControllerTest extends ControllerTestCase
{
    private const CURRENT_VERSION = 3;

    public function testCopyingASectionYieldsAStampedEnvelope(): void
    {
        $area = $this->makeArea(1);
        $section = $this->makeSection($area, 10);
        $column = $this->makeColumn($section, 100);
        $this->makeBlock($column, 1000, 0, ClipboardFixtureBlock::TYPE)->setDraftData(['title' => 'Hi']);

        $response = $this->controller([$area, $section])->copySection(10);
        $body = $this->json($response);

        $this->assertSame(ClipboardEnvelope::FORMAT, $body['format']);
        $this->assertSame(ClipboardEnvelope::SCOPE_SECTION, $body['scope']);
        $this->assertSame(self::CURRENT_VERSION, $body['contentVersion']);
        $this->assertSame(SectionTemplateSerializerInterface::FORMAT, $body['payload']['format']);
        $this->assertSame('Hi', $body['payload']['columns'][0]['blocks'][0]['data']['title']);
    }

    public function testCopyingABlockYieldsAStampedEnvelope(): void
    {
        $area = $this->makeArea(1);
        $section = $this->makeSection($area, 10);
        $column = $this->makeColumn($section, 100);
        $block = $this->makeBlock($column, 1000, 0, ClipboardFixtureBlock::TYPE);
        $block->setDraftData(['title' => 'Hi']);

        $body = $this->json($this->controller([$area, $block])->copyBlock(1000));

        $this->assertSame(ClipboardEnvelope::SCOPE_BLOCK, $body['scope']);
        $this->assertSame(BlockSnapshotSerializerInterface::FORMAT, $body['payload']['format']);
        $this->assertSame(['title' => 'Hi'], $body['payload']['data']);
    }

    public function testCopyingIsRefusedWhereTheEditorCannotEdit(): void
    {
        $area = $this->makeArea(1);
        $section = $this->makeSection($area, 10);

        $this->expectException(ContentBlocksAccessDeniedException::class);

        $this->controller([$area, $section], accessChecker: new DenyAllAccessChecker())->copySection(10);
    }

    public function testAPastedSectionLandsRightAfterTheSelectedOne(): void
    {
        [$area, $sections] = $this->areaWithSections(3);

        $body = $this->paste([$area, ...$sections], $this->sectionEnvelope(), ['targetSectionId' => $sections[0]->getId()]);

        $pasted = $this->pastedSection();
        $this->assertSame(1, $pasted->getPreviewPosition(), 'right after the selection');
        $this->assertSame([0, 2, 3], array_map(fn (Section $s) => $s->getPreviewPosition(), $sections));
        $this->assertSame($pasted->getId(), $body['sectionId']);
    }

    public function testAPastedSectionWithNothingSelectedGoesToTheEnd(): void
    {
        [$area, $sections] = $this->areaWithSections(3);

        $this->paste([$area, ...$sections], $this->sectionEnvelope());

        $this->assertSame(3, $this->pastedSection()->getPreviewPosition());
    }

    public function testAPastedBlockLandsRightAfterTheSelectedBlock(): void
    {
        $area = $this->makeArea(1);
        $section = $this->makeSection($area, 10);
        $column = $this->makeColumn($section, 100);
        $first = $this->makeBlock($column, 1000, 0, ClipboardFixtureBlock::TYPE);
        $last = $this->makeBlock($column, 1001, 1, ClipboardFixtureBlock::TYPE);

        $body = $this->paste([$area, $first, $last], $this->blockEnvelope(), ['targetBlockId' => 1000]);

        $pasted = $this->pastedBlock();
        $this->assertSame(1, $pasted->getPreviewPosition());
        $this->assertSame(2, $last->getPreviewPosition(), 'the block below moved down');
        $this->assertSame($pasted->getId(), $body['blockId']);
        $this->assertSame(10, $body['sectionId'], 'the section it landed in');
    }

    public function testAPastedBlockWithOnlyASectionSelectedLandsInItsFirstColumn(): void
    {
        $area = $this->makeArea(1);
        $section = $this->makeSection($area, 10, 0, Section::LAYOUT_TWO_COLS);
        $firstColumn = $this->makeColumn($section, 100, 0);
        $this->makeColumn($section, 101, 1);
        $existing = $this->makeBlock($firstColumn, 1000, 0, ClipboardFixtureBlock::TYPE);

        $this->paste([$area, $section], $this->blockEnvelope(), ['targetSectionId' => 10]);

        $pasted = $this->pastedBlock();
        $this->assertSame($firstColumn, $pasted->getColumn());
        $this->assertSame(1, $pasted->getPreviewPosition(), 'at the end of the column');
        $this->assertSame(0, $existing->getPreviewPosition());
    }

    public function testAPastedBlockWithNothingSelectedIsRefusedRatherThanGuessed(): void
    {
        $area = $this->makeArea(1);
        $section = $this->makeSection($area, 10);
        $this->makeColumn($section, 100);

        $response = $this->pasteResponse([$area, $section], $this->blockEnvelope());

        $this->assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());
        $this->assertSame('no_target', $this->json($response)['error']);
        $this->assertSame(0, $this->flushCount, 'nothing was written');
    }

    public function testAnotherAreaSelectionIsNotAValidTarget(): void
    {
        // The body is user-written: a section id from an area the editor may not
        // even see must not become a placement target in this one.
        $target = $this->makeArea(1);
        $foreignArea = $this->makeArea(2);
        $foreignSection = $this->makeSection($foreignArea, 20);
        $this->makeColumn($foreignSection, 200);

        $response = $this->pasteResponse(
            [$target, $foreignSection],
            $this->blockEnvelope(),
            ['targetSectionId' => 20],
        );

        $this->assertSame('no_target', $this->json($response)['error']);
    }

    public function testAnEntryCopiedUnderAnotherContentVersionIsRefused(): void
    {
        [$area, $sections] = $this->areaWithSections(1);
        $envelope = $this->sectionEnvelope();
        $envelope['contentVersion'] = self::CURRENT_VERSION - 1;

        $response = $this->pasteResponse([$area, ...$sections], $envelope);
        $body = $this->json($response);

        $this->assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());
        $this->assertSame('incompatible_content_version', $body['error']);
        $this->assertSame(self::CURRENT_VERSION - 1, $body['copiedVersion']);
        $this->assertSame(0, $this->flushCount);
    }

    public function testAForeignEnvelopeIsRefused(): void
    {
        [$area] = $this->areaWithSections(1);

        $response = $this->pasteResponse([$area], ['format' => 'something/else', 'scope' => 'section', 'payload' => []]);

        $this->assertSame('unreadable_clipboard', $this->json($response)['error']);
    }

    public function testABlockWhoseTypeIsGoneIsRefused(): void
    {
        $area = $this->makeArea(1);
        $section = $this->makeSection($area, 10);
        $this->makeColumn($section, 100);
        $envelope = $this->blockEnvelope();
        $envelope['payload']['type'] = 'retired';

        $response = $this->pasteResponse([$area, $section], $envelope, ['targetSectionId' => 10]);
        $body = $this->json($response);

        $this->assertSame('incompatible_clipboard', $body['error']);
        $this->assertSame(['retired'], $body['missingTypes']);
    }

    public function testATamperedPayloadNeverReachesTheStoredData(): void
    {
        $area = $this->makeArea(1);
        $section = $this->makeSection($area, 10);
        $this->makeColumn($section, 100);
        $envelope = $this->blockEnvelope();
        $envelope['payload']['data'] = ['title' => 'ok', 'onerror' => 'alert(1)'];

        $body = $this->paste([$area, $section], $envelope, ['targetSectionId' => 10]);

        $data = $this->pastedBlock()->getDraftData();
        $this->assertSame('ok', $data['title']);
        $this->assertArrayNotHasKey('onerror', $data);
        $this->assertSame([], $body['droppedFields'], 'an undeclared key is not a field the editor lost');
    }

    public function testAFieldTheBlockRefusesIsResetAndReported(): void
    {
        $area = $this->makeArea(1);
        $section = $this->makeSection($area, 10);
        $this->makeColumn($section, 100);
        $envelope = $this->blockEnvelope();
        $envelope['payload']['data'] = ['title' => str_repeat('x', 500)];

        $body = $this->paste([$area, $section], $envelope, ['targetSectionId' => 10]);

        $this->assertSame('', $this->pastedBlock()->getDraftData()['title']);
        $this->assertSame(
            [['blockType' => ClipboardFixtureBlock::TYPE, 'droppedFields' => ['title']]],
            $body['droppedFields'],
        );
    }

    public function testPasteIsRefusedWhereTheEditorCannotEditTheTargetArea(): void
    {
        [$area] = $this->areaWithSections(1);

        $this->expectException(ContentBlocksAccessDeniedException::class);

        $this->controller([$area], accessChecker: new DenyAllAccessChecker())
            ->paste(1, $this->makeJsonRequest(['payload' => $this->sectionEnvelope()]));
    }

    public function testPasteRequiresACsrfToken(): void
    {
        [$area] = $this->areaWithSections(1);

        $response = $this->controller([$area], csrfValid: false)
            ->paste(1, $this->makeJsonRequest(['payload' => $this->sectionEnvelope()]));

        $this->assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
        $this->assertSame(0, $this->flushCount);
    }

    // ---------- helpers ----------

    /**
     * @param list<object> $entities
     */
    private function controller(
        array $entities,
        ?object $accessChecker = null,
        bool $csrfValid = true,
    ): ClipboardController {
        $registry = new BlockTypeRegistry();
        $registry->register(new ClipboardFixtureBlock());

        $factory = Forms::createFormFactoryBuilder()
            ->addExtension(new ValidatorExtension(Validation::createValidator()))
            ->addType(new BlockFormType(new BlockFormExtensionCollection()))
            ->getFormFactory();

        $paster = new ClipboardPaster(
            // The shared makeDataKeys() helper builds a bare factory, which has
            // no `constraints` option — this fixture block declares one.
            new SectionTemplateInstantiator($registry, new BlockDataKeys($registry, $factory)),
            $registry,
            new BlockDataReplayer($registry, $factory, new BlockDataDefaults(), new CollectionItemIds()),
        );

        return new ClipboardController(
            $this->makeEm($entities),
            $accessChecker ?? $this->makeAccessChecker(),
            new SectionTemplateSerializer(),
            new BlockSnapshotSerializer(),
            $paster,
            $this->makeCsrfManagerFor($csrfValid),
            self::CURRENT_VERSION,
        );
    }

    private function makeCsrfManagerFor(bool $valid): CsrfTokenManagerInterface
    {
        return $this->makeCsrfManager($valid);
    }

    /**
     * @param list<object>         $entities
     * @param array<string, mixed> $envelope
     * @param array<string, mixed> $target
     *
     * @return array<string, mixed>
     */
    private function paste(array $entities, array $envelope, array $target = []): array
    {
        $response = $this->pasteResponse($entities, $envelope, $target);
        $this->assertSame(Response::HTTP_OK, $response->getStatusCode(), (string) $response->getContent());

        return $this->json($response);
    }

    /**
     * @param list<object>         $entities
     * @param array<string, mixed> $envelope
     * @param array<string, mixed> $target
     */
    private function pasteResponse(array $entities, array $envelope, array $target = []): Response
    {
        $area = $entities[0];
        \assert($area instanceof \ContentBlocks\Entity\ContentArea);

        return $this->controller($entities)->paste(
            (int) $area->getId(),
            $this->makeJsonRequest(['payload' => $envelope, ...$target]),
        );
    }

    /**
     * @return array{0: \ContentBlocks\Entity\ContentArea, 1: list<Section>}
     */
    private function areaWithSections(int $count): array
    {
        $area = $this->makeArea(1);
        $sections = [];
        for ($i = 0; $i < $count; ++$i) {
            $section = $this->makeSection($area, 10 + $i, $i);
            $this->makeColumn($section, 100 + $i);
            $sections[] = $section;
        }

        return [$area, $sections];
    }

    /** @return array<string, mixed> */
    private function sectionEnvelope(): array
    {
        return [
            'format' => ClipboardEnvelope::FORMAT,
            'scope' => ClipboardEnvelope::SCOPE_SECTION,
            'contentVersion' => self::CURRENT_VERSION,
            'payload' => [
                'format' => SectionTemplateSerializerInterface::FORMAT,
                'layout' => Section::LAYOUT_FULL,
                'settings' => null,
                'columns' => [[
                    'preset' => 'col-12',
                    'blocks' => [['type' => ClipboardFixtureBlock::TYPE, 'data' => ['title' => 'Copied']]],
                ]],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function blockEnvelope(): array
    {
        return [
            'format' => ClipboardEnvelope::FORMAT,
            'scope' => ClipboardEnvelope::SCOPE_BLOCK,
            'contentVersion' => self::CURRENT_VERSION,
            'payload' => [
                'format' => BlockSnapshotSerializerInterface::FORMAT,
                'type' => ClipboardFixtureBlock::TYPE,
                'data' => ['title' => 'Copied'],
            ],
        ];
    }

    private function pastedSection(): Section
    {
        foreach ($this->persisted as $entity) {
            if ($entity instanceof Section) {
                return $entity;
            }
        }

        $this->fail('No section was persisted.');
    }

    private function pastedBlock(): Block
    {
        foreach ($this->persisted as $entity) {
            if ($entity instanceof Block) {
                return $entity;
            }
        }

        $this->fail('No block was persisted.');
    }

    /** @return array<string, mixed> */
    private function json(Response $response): array
    {
        return json_decode((string) $response->getContent(), true, flags: \JSON_THROW_ON_ERROR);
    }
}

/** A block with one real, constrained field — enough to exercise the replay. */
final class ClipboardFixtureBlock extends AbstractBlockType
{
    public const TYPE = 'clip_fixture';

    public static function getType(): string
    {
        return self::TYPE;
    }

    public static function getLabel(): string
    {
        return 'Clipboard fixture';
    }

    public function buildForm(FormBuilderInterface $builder, array $data): void
    {
        $builder->add('title', TextType::class, [
            'required' => false,
            'data' => $data['title'] ?? '',
            'constraints' => [new Assert\Length(max: 20)],
        ]);
    }

    public function getDefaultData(): array
    {
        return ['title' => ''];
    }

    public function supportsPreviewHotReload(): bool
    {
        return false;
    }
}
