<?php

declare(strict_types=1);

namespace ContentBlocks\Tests\Ai;

use ContentBlocks\Ai\GeneratedContentWriter;
use ContentBlocks\Ai\GenerationRefusedException;
use ContentBlocks\Block\BlockDataDefaults;
use ContentBlocks\Block\BlockDataKeys;
use ContentBlocks\Block\CollectionItemIds;
use ContentBlocks\BlockType\AbstractBlockType;
use ContentBlocks\BlockType\BlockTypeRegistry;
use ContentBlocks\Builder\BuilderStructure;
use ContentBlocks\Clipboard\BlockDataReplayer;
use ContentBlocks\Clipboard\ClipboardPaster;
use ContentBlocks\Content\ContentManipulator;
use ContentBlocks\Content\DraftOrder;
use ContentBlocks\Entity\Block;
use ContentBlocks\Entity\Column;
use ContentBlocks\Entity\ContentArea;
use ContentBlocks\Entity\Section;
use ContentBlocks\Form\Extension\BlockFormExtensionCollection;
use ContentBlocks\Form\Type\BlockFormType;
use ContentBlocks\Form\Type\PaletteColorType;
use ContentBlocks\Form\Type\SectionSettingsType;
use ContentBlocks\Section\RestoredStructure;
use ContentBlocks\Section\SectionStyleRegistry;
use ContentBlocks\SectionTemplate\SectionTemplateInstantiator;
use ContentBlocks\Tests\Fixtures\FixedBuilderStructureResolver;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Validator\ValidatorExtension;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\Forms;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Validation;

/**
 * The way back in: a model's document reaches the draft only through the
 * paste replay, so what the forms refuse never lands.
 */
final class GeneratedContentWriterTest extends TestCase
{
    public function testASectionIsWrittenToTheDraftThroughEachBlockForm(): void
    {
        $area = new ContentArea();

        $result = $this->writer()->write($area, ['sections' => [
            self::section([['type' => 'gen', 'data' => ['title' => 'Hello', 'size' => 'lg', 'onclick' => 'x()']]]),
        ]]);

        $this->assertCount(1, $result->sections);
        $block = self::blocksOf($result->sections[0])[0];
        $this->assertSame('Hello', $block->getDraftData()['title']);
        $this->assertSame('lg', $block->getDraftData()['size']);
        $this->assertArrayNotHasKey('onclick', $block->getDraftData(), 'an undeclared key never lands');
        $this->assertNull($block->getPublishedData(), 'a draft, not the live page');
    }

    public function testARefusedValueFallsBackToTheDefaultAndIsReported(): void
    {
        $result = $this->writer()->write(new ContentArea(), ['sections' => [
            self::section([['type' => 'gen', 'data' => ['title' => 'Hi', 'size' => 'enormous']]]),
        ]]);

        $this->assertSame('sm', self::blocksOf($result->sections[0])[0]->getDraftData()['size']);
        $this->assertSame([['blockType' => 'gen', 'droppedFields' => ['size']]], $result->droppedFields);
    }

    public function testAFieldOfTheWrongShapeCostsTheFieldNotThePage(): void
    {
        // Found with a real model: a list written as strings, not objects.
        $result = $this->writer()->write(new ContentArea(), ['sections' => [
            self::section([['type' => 'gen', 'data' => ['title' => 'Kept', 'size' => ['lg']]]]),
        ]]);

        $data = self::blocksOf($result->sections[0])[0]->getDraftData();
        $this->assertSame('Kept', $data['title']);
        $this->assertSame('sm', $data['size']);
        $this->assertSame([['blockType' => 'gen', 'droppedFields' => ['size']]], $result->droppedFields);
    }

    public function testAnUnknownBlockTypeIsSkippedAndReported(): void
    {
        $result = $this->writer()->write(new ContentArea(), ['sections' => [
            self::section([['type' => 'carousel', 'data' => []], ['type' => 'gen', 'data' => ['title' => 'Kept']]]),
        ]]);

        $this->assertSame(['carousel'], $result->skippedBlockTypes);
        $this->assertCount(1, self::blocksOf($result->sections[0]));
    }

    public function testASectionWhoseBlocksAreAllUnknownIsLeftOut(): void
    {
        $result = $this->writer()->write(new ContentArea(), ['sections' => [
            self::section([['type' => 'carousel', 'data' => []]]),
            self::section([['type' => 'gen', 'data' => ['title' => 'Kept']]]),
        ]]);

        $this->assertCount(1, $result->sections);
        $this->assertSame(['carousel'], $result->skippedBlockTypes);
    }

    public function testSettingsKeepOnlyTheKeysTheSectionFormDeclares(): void
    {
        $result = $this->writer()->write(new ContentArea(), ['sections' => [
            self::section([['type' => 'gen', 'data' => []]], ['widthMode' => 'centered', 'injected' => 'x']),
        ]]);

        $this->assertSame(['widthMode' => 'centered'], $result->sections[0]->getDraftSettings());
    }

    public function testAMissingFormatIsSuppliedAndAnUnknownLayoutFallsBack(): void
    {
        $result = $this->writer()->write(new ContentArea(), ['sections' => [[
            'layout' => 'seven_cols',
            'columns' => [['preset' => 'col-12', 'blocks' => [['type' => 'gen', 'data' => []]]]],
        ]]]);

        $this->assertSame(Section::LAYOUT_FULL, $result->sections[0]->getLayout());
    }

    public function testSectionsFollowEachOtherAfterTheTarget(): void
    {
        $area = new ContentArea();
        $first = $this->existingSection($area);
        $last = $this->existingSection($area);

        $result = $this->writer()->write($area, ['sections' => [
            self::section([['type' => 'gen', 'data' => ['title' => 'A']]]),
            self::section([['type' => 'gen', 'data' => ['title' => 'B']]]),
        ]], $first);

        $this->assertSame(
            [$first, $result->sections[0], $result->sections[1], $last],
            DraftOrder::sections($area),
        );
    }

    public function testARevisionTakesThePlaceOfItsSection(): void
    {
        $area = new ContentArea();
        $first = $this->existingSection($area);
        $target = $this->existingSection($area);
        $last = $this->existingSection($area);

        $result = $this->writer()->write($area, ['sections' => [
            self::section([['type' => 'gen', 'data' => ['title' => 'Revised']]]),
        ]], replacing: $target);

        $this->assertTrue($target->isDeleted(), 'a draft delete: Ctrl-Z or Discard brings it back');
        $this->assertSame([$first, $result->sections[0], $last], DraftOrder::sections($area));
    }

    public function testARefusedRevisionLeavesItsSectionStanding(): void
    {
        $area = new ContentArea();
        $target = $this->existingSection($area);

        try {
            $this->writer()->write($area, ['sections' => [
                self::section([['type' => 'carousel', 'data' => []]]),
            ]], replacing: $target);
            $this->fail('nothing survived, so the write is refused');
        } catch (GenerationRefusedException) {
        }

        $this->assertFalse($target->isDeleted());
    }

    public function testTheSectionCountIsCapped(): void
    {
        $result = $this->writer()->write(new ContentArea(), ['sections' => array_fill(
            0,
            5,
            self::section([['type' => 'gen', 'data' => []]]),
        )], null, 2);

        $this->assertCount(2, $result->sections);
    }

    public function testReplacingDeletesTheDraftSectionsFirst(): void
    {
        $area = new ContentArea();
        $old = $this->existingSection($area);

        $result = $this->writer()->write($area, ['sections' => [
            self::section([['type' => 'gen', 'data' => ['title' => 'New']]]),
        ]], replace: true);

        $this->assertTrue($old->isDeleted(), 'a draft delete: Discard brings it back');
        $this->assertSame([$result->sections[0]], DraftOrder::sections($area));
    }

    public function testWithFixedSectionsNothingIsWritten(): void
    {
        $this->expectRefusal(GenerationRefusedException::SECTIONS_LOCKED);

        $this->writer(new BuilderStructure(BuilderStructure::SECTIONS_FIXED))->write(new ContentArea(), [
            'sections' => [self::section([['type' => 'gen', 'data' => []]])],
        ]);
    }

    public function testWithHiddenSectionsTheBlocksGoToTheOneColumn(): void
    {
        $area = new ContentArea();
        $section = $this->existingSection($area);
        $column = $section->getColumns()->first();
        \assert($column instanceof Column);

        $result = $this->writer(new BuilderStructure(BuilderStructure::SECTIONS_HIDDEN))->write($area, [
            'sections' => [
                self::section([['type' => 'gen', 'data' => ['title' => 'A']]]),
                self::section([['type' => 'gen', 'data' => ['title' => 'B']]]),
            ],
        ]);

        $this->assertSame([], $result->sections, 'no section is added');
        $this->assertSame(['A', 'B'], array_map(
            static fn (Block $b): string => $b->getDraftData()['title'],
            DraftOrder::blocks($column),
        ));
    }

    /**
     * @param array<mixed> $document
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('unusable')]
    public function testAnUnusableDocumentIsRefused(array $document, string $reason): void
    {
        $this->expectRefusal($reason);

        $this->writer()->write(new ContentArea(), $document);
    }

    /**
     * @return iterable<string, array{array<mixed>, string}>
     */
    public static function unusable(): iterable
    {
        yield 'no sections key' => [['blocks' => []], GenerationRefusedException::UNREADABLE];
        yield 'sections as a map' => [['sections' => ['a' => []]], GenerationRefusedException::UNREADABLE];
        yield 'no section' => [['sections' => []], GenerationRefusedException::EMPTY];
        yield 'nothing survives' => [
            ['sections' => [self::section([['type' => 'carousel', 'data' => []]])]],
            GenerationRefusedException::EMPTY,
        ];
        yield 'more blocks than a page' => [
            ['sections' => [self::section(array_fill(0, RestoredStructure::MAX_BLOCKS + 1, ['type' => 'gen']))]],
            GenerationRefusedException::TOO_LARGE,
        ];
    }

    private function expectRefusal(string $reason): void
    {
        $this->expectException(GenerationRefusedException::class);
        $this->expectExceptionMessage($reason);
    }

    /**
     * @param list<array<string, mixed>> $blocks
     * @param array<string, mixed>|null  $settings
     *
     * @return array<string, mixed>
     */
    private static function section(array $blocks, ?array $settings = null): array
    {
        return [
            'format' => 'content-blocks/section-v1',
            'layout' => 'full',
            'settings' => $settings,
            'columns' => [['preset' => 'col-12', 'blocks' => $blocks]],
        ];
    }

    /** @return list<Block> */
    private static function blocksOf(Section $section): array
    {
        $column = $section->getColumns()->first();

        return $column instanceof Column ? DraftOrder::blocks($column) : [];
    }

    private function existingSection(ContentArea $area): Section
    {
        $section = new Section();
        $section->setPreviewPosition(\count($area->getSections()));
        $column = new Column();
        $column->setPreset('col-12');
        $section->addColumn($column);
        $area->addSection($section);

        return $section;
    }

    private function writer(?BuilderStructure $structure = null): GeneratedContentWriter
    {
        $registry = new BlockTypeRegistry();
        $registry->register(new GenFixtureBlock());

        $factory = Forms::createFormFactoryBuilder()
            ->addExtension(new ValidatorExtension(Validation::createValidator()))
            ->addType(new BlockFormType(new BlockFormExtensionCollection()))
            ->addType(new SectionSettingsType(new SectionStyleRegistry()))
            ->addType(new PaletteColorType())
            ->getFormFactory();

        $content = new ContentManipulator(null, $registry);
        $paster = new ClipboardPaster(
            new SectionTemplateInstantiator($registry, new BlockDataKeys($registry, $factory)),
            $registry,
            new BlockDataReplayer($registry, $factory, new BlockDataDefaults(), new CollectionItemIds()),
            $content,
        );

        return new GeneratedContentWriter(
            $paster,
            $content,
            new FixedBuilderStructureResolver($structure ?? new BuilderStructure()),
            $factory,
        );
    }
}

final class GenFixtureBlock extends AbstractBlockType
{
    public function getType(): string
    {
        return 'gen';
    }

    public function getLabel(): string
    {
        return 'Generated fixture';
    }

    public function buildForm(FormBuilderInterface $builder, array $data): void
    {
        $builder
            ->add('title', TextType::class, ['required' => false, 'constraints' => [new Assert\Length(max: 20)]])
            ->add('size', ChoiceType::class, ['choices' => ['Small' => 'sm', 'Large' => 'lg']]);
    }

    public function getDefaultData(): array
    {
        return ['title' => '', 'size' => 'sm'];
    }
}
