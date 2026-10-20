<?php

declare(strict_types=1);

namespace ContentBlocks\Tests\Schema;

use ContentBlocks\BlockType\AbstractBlockType;
use ContentBlocks\BlockType\BlockPurposeInterface;
use ContentBlocks\BlockType\BlockTypeRegistry;
use ContentBlocks\Builder\BuilderStructure;
use ContentBlocks\Entity\ContentArea;
use ContentBlocks\Entity\SectionTemplate;
use ContentBlocks\Form\Extension\BlockFormExtensionCollection;
use ContentBlocks\Form\Type\BlockFormType;
use ContentBlocks\Form\Type\PaletteColorType;
use ContentBlocks\Form\Type\SectionSettingsType;
use ContentBlocks\Palette\ColorPaletteRegistry;
use ContentBlocks\Palette\ConfigColorPaletteProvider;
use ContentBlocks\Schema\ContentSchema;
use ContentBlocks\Schema\ContentSchemaBuilder;
use ContentBlocks\Schema\FormSchemaMapper;
use ContentBlocks\Schema\PaletteColorFieldSchema;
use ContentBlocks\Schema\SectionTemplateExamples;
use ContentBlocks\Section\ConfigSectionStyleProvider;
use ContentBlocks\Section\SectionLayoutRegistry;
use ContentBlocks\Section\SectionStyleRegistry;
use ContentBlocks\SectionTemplate\SectionTemplateSerializerInterface;
use ContentBlocks\Tests\Fixtures\FixedBuilderStructureResolver;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Validator\ValidatorExtension;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\Forms;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Validation;

final class ContentSchemaBuilderTest extends TestCase
{
    public function testTheDocumentNamesItsFormatAndVersion(): void
    {
        $document = $this->builder(contentVersion: 4)->build()->toArray();

        $this->assertSame(ContentSchema::FORMAT, $document['format']);
        $this->assertSame(4, $document['contentVersion']);
        $this->assertSame(
            ['format', 'contentVersion', 'structure', 'layouts', 'palette', 'sectionStyles', 'blocks', 'examples', 'schema'],
            array_keys($document),
        );
    }

    public function testEachBlockTypeIsListedWithItsPurpose(): void
    {
        $blocks = $this->builder()->build()->blocks;

        $this->assertSame([
            ['type' => 'hero', 'label' => 'Hero', 'category' => 'other', 'purpose' => 'Opens a landing page.'],
            ['type' => 'note', 'label' => 'Note', 'category' => 'other'],
        ], $blocks, 'a block without a purpose simply has none');
    }

    public function testAConfiguredPurposeWinsOverTheBlockOwn(): void
    {
        $blocks = $this->builder(purposes: ['hero' => 'Our product banner.', 'note' => '  '])->build()->blocks;

        $this->assertSame('Our product banner.', $blocks[0]['purpose']);
        $this->assertArrayNotHasKey('purpose', $blocks[1], 'a blank override is no override');
    }

    public function testEachBlockTypeIsABranchOfTheBlockUnion(): void
    {
        $defs = $this->builder()->build()->schema['$defs'];

        $this->assertSame(
            [['$ref' => '#/$defs/block.hero'], ['$ref' => '#/$defs/block.note']],
            $defs['block']['oneOf'],
        );
        $hero = $defs['block.hero'];
        $this->assertSame(['const' => 'hero'], $hero['properties']['type']);
        $this->assertSame(['type', 'data'], $hero['required']);
        $this->assertSame(['sm', 'lg'], $hero['properties']['data']['properties']['size']['enum']);
        $this->assertSame(80, $hero['properties']['data']['properties']['heading']['maxLength']);
    }

    public function testADeclaredKeyWithoutAFieldIsTypedFromItsDefault(): void
    {
        // The replay keeps such a key verbatim, so it is part of the data.
        $data = $this->builder()->build()->schema['$defs']['block.hero']['properties']['data'];

        $this->assertSame(['type' => 'boolean', 'default' => true], $data['properties']['sticky']);
    }

    public function testBlockStylingIsDescribedOnceAndReferenced(): void
    {
        $defs = $this->builder()->build()->schema['$defs'];

        $this->assertSame('object', $defs['blockStyling']['type']);
        $this->assertSame(['$ref' => '#/$defs/blockStyling'], $defs['block.hero']['properties']['data']['properties']['styling']);
        $this->assertSame(['$ref' => '#/$defs/blockStyling'], $defs['block.note']['properties']['data']['properties']['styling']);
    }

    public function testNoBlockStylingWhenTheHostHidesIt(): void
    {
        $defs = $this->builder(blockStyling: false)->build()->schema['$defs'];

        $this->assertArrayNotHasKey('blockStyling', $defs);
        $this->assertArrayNotHasKey('styling', $defs['block.hero']['properties']['data']['properties']);
    }

    public function testLayoutsCarryTheirColumnPresets(): void
    {
        $schema = $this->builder()->build();

        $this->assertSame(
            ['name' => 'two_cols', 'label' => 'cb.section.layout.two_cols', 'columns' => ['col-6', 'col-6'], 'display' => 'grid'],
            $schema->layouts[1],
        );
        $this->assertSame(
            ['full', 'two_cols', 'three_cols'],
            $schema->schema['$defs']['section']['properties']['layout']['enum'],
        );
    }

    public function testThePaletteCarriesToneAndRole(): void
    {
        $this->assertSame([
            ['label' => 'Brand', 'color' => '#eb0540', 'tone' => 'dark', 'role' => 'Calls to action only.'],
            ['label' => 'Paper', 'color' => '#ffffff', 'tone' => 'light'],
        ], $this->builder()->build()->palette);
    }

    public function testSectionStylesCarryTheirRoleNotTheirSettings(): void
    {
        $this->assertSame(
            [['name' => 'boxed', 'label' => 'Boxed', 'role' => 'A highlighted band.']],
            $this->builder()->build()->sectionStyles,
        );
    }

    public function testSectionSettingsComeFromTheirForm(): void
    {
        $settings = $this->builder()->build()->schema['$defs']['sectionSettings'];

        $this->assertSame(['boxed', null], $settings['properties']['styleName']['enum']);
        $this->assertArrayHasKey('display', $settings['properties']);
    }

    public function testASectionIsTheShapeThePastePathReads(): void
    {
        $section = $this->builder()->build()->schema['$defs']['section'];

        $this->assertSame(['const' => SectionTemplateSerializerInterface::FORMAT], $section['properties']['format']);
        $this->assertSame(['format', 'layout', 'columns'], $section['required']);
        $this->assertSame(ContentSchemaBuilder::MAX_COLUMNS, $section['properties']['columns']['maxItems']);
    }

    public function testWithHiddenSectionsThePageIsOneFullSectionWithoutSettings(): void
    {
        $area = new ContentArea();
        $schema = $this->builder(structure: new BuilderStructure(BuilderStructure::SECTIONS_HIDDEN))
            ->build($area);

        $this->assertSame(['sections' => 'hidden', 'columns' => false], $schema->structure);
        $this->assertSame(1, $schema->schema['properties']['sections']['maxItems']);
        $this->assertSame(['full'], $schema->schema['$defs']['section']['properties']['layout']['enum']);
        $this->assertArrayNotHasKey('settings', $schema->schema['$defs']['section']['properties']);
        $this->assertArrayNotHasKey('sectionSettings', $schema->schema['$defs']);
        $this->assertSame([], $schema->examples, 'saved sections would teach a shape refused here');
    }

    public function testWithoutAnAreaTheConfiguredStructureApplies(): void
    {
        $schema = $this->builder(
            structure: new BuilderStructure(BuilderStructure::SECTIONS_HIDDEN),
            configuredSections: BuilderStructure::SECTIONS_FIXED,
        )->build();

        $this->assertSame(['sections' => 'fixed', 'columns' => true], $schema->structure);
    }

    public function testSavedSectionsComeAsExamples(): void
    {
        $payload = [
            'format' => SectionTemplateSerializerInterface::FORMAT,
            'layout' => 'full',
            'columns' => [['preset' => 'col-12', 'blocks' => [['type' => 'note', 'data' => ['text' => 'Hi']]]]],
        ];
        $template = (new SectionTemplate())->setName('Hero')->setPayload($payload)->setBlockTypes(['note']);
        $template->setContentVersion(1);

        $schema = $this->builder(templates: [$template])->build(null, null, 2);

        $this->assertSame([['name' => 'Hero', 'section' => $payload]], $schema->examples);
    }

    /**
     * @param array<string, string> $purposes
     * @param list<SectionTemplate>  $templates
     */
    private function builder(
        int $contentVersion = 1,
        array $purposes = [],
        bool $blockStyling = true,
        ?BuilderStructure $structure = null,
        string $configuredSections = BuilderStructure::SECTIONS_EDITABLE,
        array $templates = [],
    ): ContentSchemaBuilder {
        $registry = new BlockTypeRegistry();
        $registry->register(new HeroFixtureBlock());
        $registry->register(new NoteFixtureBlock());

        $palette = new ColorPaletteRegistry([new ConfigColorPaletteProvider([
            ['label' => 'Brand', 'color' => '#eb0540', 'role' => 'Calls to action only.'],
            ['label' => 'Paper', 'color' => '#ffffff'],
        ])]);
        $styles = new SectionStyleRegistry([new ConfigSectionStyleProvider([
            ['name' => 'boxed', 'label' => 'Boxed', 'role' => 'A highlighted band.', 'settings' => ['widthMode' => 'centered']],
        ])]);

        $factory = Forms::createFormFactoryBuilder()
            ->addExtension(new ValidatorExtension(Validation::createValidator()))
            ->addType(new BlockFormType(new BlockFormExtensionCollection()))
            ->addType(new SectionSettingsType($styles))
            ->addType(new PaletteColorType($palette))
            ->getFormFactory();

        return new ContentSchemaBuilder(
            $registry,
            $factory,
            new FormSchemaMapper([new PaletteColorFieldSchema($palette)]),
            palette: $palette,
            styles: $styles,
            layouts: new SectionLayoutRegistry(),
            structureResolver: new FixedBuilderStructureResolver($structure ?? new BuilderStructure()),
            examples: $this->examples($registry, $templates),
            blockStyling: $blockStyling,
            structureSections: $configuredSections,
            contentVersion: $contentVersion,
            schemaBlockPurposes: $purposes,
        );
    }

    /**
     * @param list<SectionTemplate> $templates
     */
    private function examples(BlockTypeRegistry $registry, array $templates): SectionTemplateExamples
    {
        $repository = $this->createMock(EntityRepository::class);
        $repository->method('findBy')->willReturn($templates);
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($repository);

        return new SectionTemplateExamples($em, $registry);
    }
}

final class HeroFixtureBlock extends AbstractBlockType implements BlockPurposeInterface
{
    public function getPurpose(): string
    {
        return 'Opens a landing page.';
    }

    public function getType(): string
    {
        return 'hero';
    }

    public function getLabel(): string
    {
        return 'Hero';
    }

    public function buildForm(FormBuilderInterface $builder, array $data): void
    {
        $builder
            ->add('heading', TextType::class, ['constraints' => [new Assert\Length(max: 80)]])
            ->add('size', ChoiceType::class, ['choices' => ['Small' => 'sm', 'Large' => 'lg']]);
    }

    public function getDefaultData(): array
    {
        return ['heading' => '', 'size' => 'sm', 'sticky' => true];
    }
}

final class NoteFixtureBlock extends AbstractBlockType
{
    public function getType(): string
    {
        return 'note';
    }

    public function getLabel(): string
    {
        return 'Note';
    }

    public function buildForm(FormBuilderInterface $builder, array $data): void
    {
        $builder->add('text', TextType::class);
    }

    public function getDefaultData(): array
    {
        return ['text' => ''];
    }
}
