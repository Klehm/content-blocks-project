<?php

declare(strict_types=1);

namespace ContentBlocks\Tests\Builder;

use ContentBlocks\BlockType\AbstractBlockType;
use ContentBlocks\BlockType\BlockPreviewHint;
use ContentBlocks\BlockType\BlockPreviewHintInterface;
use ContentBlocks\BlockType\BlockTypeRegistry;
use ContentBlocks\Builder\AreaTreeBuilder;
use ContentBlocks\Entity\Block;
use ContentBlocks\Entity\Column;
use ContentBlocks\Entity\ContentArea;
use ContentBlocks\Entity\Section;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

final class AreaTreeBuilderTest extends TestCase
{
    private function makeBuilder(): AreaTreeBuilder
    {
        $registry = new BlockTypeRegistry();
        $registry->register(new TreeHeadingBlock());
        $registry->register(new TreeMuteBlock());
        $registry->register(new TreeExplodingBlock());

        return new AreaTreeBuilder($registry, new ParamTranslator());
    }

    public function testOutlineFollowsTheDraftOrderNotThePublishedOne(): void
    {
        $area = $this->makeArea();
        $first = $this->makeSection($area, 1, previewPosition: 1);
        $second = $this->makeSection($area, 2, previewPosition: 0);

        $tree = $this->makeBuilder()->build($area);

        $this->assertSame(
            [$second->getId(), $first->getId()],
            array_column($tree['sections'], 'id'),
        );
    }

    public function testSoftDeletedNodesArePrunedAtEveryLevel(): void
    {
        $area = $this->makeArea();
        $kept = $this->makeSection($area, 1);
        $gone = $this->makeSection($area, 2, previewPosition: 1);
        $gone->setDeleted(true);
        $column = $this->makeColumn($kept, 10);
        $deadColumn = $this->makeColumn($kept, 11, previewPosition: 1);
        $deadColumn->setDeleted(true);
        $this->makeBlock($column, 100);
        $this->makeBlock($column, 101, previewPosition: 1)->setDeleted(true);

        $tree = $this->makeBuilder()->build($area);

        $this->assertCount(1, $tree['sections']);
        $this->assertCount(1, $tree['sections'][0]['columns']);
        $this->assertSame([100], array_column($tree['sections'][0]['columns'][0]['blocks'], 'id'));
    }

    public function testABlockRowReadsItsPreviewHint(): void
    {
        $area = $this->makeArea();
        $column = $this->makeColumn($this->makeSection($area, 1), 10);
        $block = $this->makeBlock($column, 100, type: TreeHeadingBlock::TYPE);
        $block->setDraftData(['title' => 'Welcome aboard']);

        $row = $this->makeBuilder()->build($area)['sections'][0]['columns'][0]['blocks'][0];

        $this->assertSame('Welcome aboard', $row['label']);
        $this->assertSame(BlockPreviewHint::KIND_HEADING, $row['kind']);
        $this->assertSame('Heading', $row['typeLabel']);
        $this->assertFalse($row['missing']);
    }

    public function testARowCarriesTheBlockTypesOwnIcon(): void
    {
        $area = $this->makeArea();
        $column = $this->makeColumn($this->makeSection($area, 1), 10);
        $this->makeBlock($column, 100, type: TreeHeadingBlock::TYPE);
        $this->makeBlock($column, 101, previewPosition: 1, type: TreeMuteBlock::TYPE);

        $blocks = $this->makeBuilder()->build($area)['sections'][0]['columns'][0]['blocks'];

        $this->assertSame('<svg data-heading></svg>', $blocks[0]['icon']);
        // No icon is not an error: the panel draws a generic glyph.
        $this->assertNull($blocks[1]['icon']);
    }

    public function testABlockWithNoHintFallsBackToItsTypeLabel(): void
    {
        $area = $this->makeArea();
        $column = $this->makeColumn($this->makeSection($area, 1), 10);
        $this->makeBlock($column, 100, type: TreeMuteBlock::TYPE);

        $row = $this->makeBuilder()->build($area)['sections'][0]['columns'][0]['blocks'][0];

        $this->assertSame('Mute', $row['label']);
        $this->assertSame(BlockPreviewHint::KIND_GENERIC, $row['kind']);
    }

    public function testAThrowingHintCostsThatRowItsSummaryOnly(): void
    {
        $area = $this->makeArea();
        $column = $this->makeColumn($this->makeSection($area, 1), 10);
        $this->makeBlock($column, 100, type: TreeExplodingBlock::TYPE);
        $this->makeBlock($column, 101, previewPosition: 1, type: TreeMuteBlock::TYPE);

        $blocks = $this->makeBuilder()->build($area)['sections'][0]['columns'][0]['blocks'];

        $this->assertSame('Exploding', $blocks[0]['label']);
        $this->assertSame('Mute', $blocks[1]['label']);
    }

    public function testAnUnregisteredTypeStillEarnsARowFlaggedMissing(): void
    {
        $area = $this->makeArea();
        $column = $this->makeColumn($this->makeSection($area, 1), 10);
        $this->makeBlock($column, 100, type: 'from_another_build');

        $row = $this->makeBuilder()->build($area)['sections'][0]['columns'][0]['blocks'][0];

        $this->assertTrue($row['missing']);
        $this->assertSame('from_another_build', $row['label']);
        $this->assertNull($row['icon']);
    }

    public function testTheDraftDataWinsOverThePublishedOne(): void
    {
        $area = $this->makeArea();
        $column = $this->makeColumn($this->makeSection($area, 1), 10);
        $block = $this->makeBlock($column, 100, type: TreeHeadingBlock::TYPE);
        $block->setPublishedData(['title' => 'Live']);
        $block->setDraftData(['title' => 'Work in progress']);

        $row = $this->makeBuilder()->build($area)['sections'][0]['columns'][0]['blocks'][0];

        $this->assertSame('Work in progress', $row['label']);
    }

    public function testSectionAndColumnLabelsCarryTheirOneBasedIndex(): void
    {
        $area = $this->makeArea();
        $section = $this->makeSection($area, 1, layout: Section::LAYOUT_TWO_COLS);
        $this->makeColumn($section, 10);
        $this->makeColumn($section, 11, previewPosition: 1);

        $tree = $this->makeBuilder()->build($area);

        $this->assertSame('cb.section.label{%index%:1,%layout%:cb.section.layout.two_cols}', $tree['sections'][0]['label']);
        $this->assertSame('cb.builder.tree.column{%index%:2}', $tree['sections'][0]['columns'][1]['label']);
        $this->assertSame('col-12', $tree['sections'][0]['columns'][0]['preset']);
    }

    // ---------- Entity factories (ids assigned as Doctrine would) ----------

    private function setEntityId(object $entity, int $id): void
    {
        (new \ReflectionProperty($entity::class, 'id'))->setValue($entity, $id);
    }

    private function makeArea(int $id = 7): ContentArea
    {
        $area = new ContentArea();
        $this->setEntityId($area, $id);

        return $area;
    }

    private function makeSection(
        ContentArea $area,
        int $id,
        int $previewPosition = 0,
        string $layout = Section::LAYOUT_FULL,
    ): Section {
        $section = new Section();
        $this->setEntityId($section, $id);
        $section->setLayout($layout);
        $section->setPreviewPosition($previewPosition);
        $area->addSection($section);

        return $section;
    }

    private function makeColumn(Section $section, int $id, int $previewPosition = 0): Column
    {
        $column = new Column();
        $this->setEntityId($column, $id);
        $column->setPreset('col-12');
        $column->setPreviewPosition($previewPosition);
        $section->addColumn($column);

        return $column;
    }

    private function makeBlock(
        Column $column,
        int $id,
        int $previewPosition = 0,
        string $type = TreeMuteBlock::TYPE,
    ): Block {
        $block = new Block();
        $this->setEntityId($block, $id);
        $block->setType($type);
        $block->setPreviewPosition($previewPosition);
        $column->addBlock($block);

        return $block;
    }
}

/** Echoes the id *and* its parameters, so a label assertion sees both. */
final class ParamTranslator implements TranslatorInterface
{
    public function trans(string $id, array $parameters = [], ?string $domain = null, ?string $locale = null): string
    {
        if ($parameters === []) {
            return $id;
        }
        $pairs = [];
        foreach ($parameters as $key => $value) {
            $pairs[] = $key . ':' . $value;
        }

        return $id . '{' . implode(',', $pairs) . '}';
    }

    public function getLocale(): string
    {
        return 'en';
    }
}

final class TreeHeadingBlock extends AbstractBlockType implements BlockPreviewHintInterface
{
    public const TYPE = 'tree_heading';

    public static function getType(): string
    {
        return self::TYPE;
    }

    public static function getLabel(): string
    {
        return 'Heading';
    }

    public static function getIcon(): ?string
    {
        return '<svg data-heading></svg>';
    }

    public function buildForm(FormBuilderInterface $builder, array $data): void
    {
    }

    public function getDefaultData(): array
    {
        return ['title' => ''];
    }

    public function previewHint(array $data): ?BlockPreviewHint
    {
        return BlockPreviewHint::heading(is_string($data['title'] ?? null) ? $data['title'] : null);
    }
}

/** Implements nothing optional: the honest "just name the type" row. */
final class TreeMuteBlock extends AbstractBlockType
{
    public const TYPE = 'tree_mute';

    public static function getType(): string
    {
        return self::TYPE;
    }

    public static function getLabel(): string
    {
        return 'Mute';
    }

    public function buildForm(FormBuilderInterface $builder, array $data): void
    {
    }

    public function getDefaultData(): array
    {
        return [];
    }
}

final class TreeExplodingBlock extends AbstractBlockType implements BlockPreviewHintInterface
{
    public const TYPE = 'tree_boom';

    public static function getType(): string
    {
        return self::TYPE;
    }

    public static function getLabel(): string
    {
        return 'Exploding';
    }

    public function buildForm(FormBuilderInterface $builder, array $data): void
    {
    }

    public function getDefaultData(): array
    {
        return [];
    }

    public function previewHint(array $data): ?BlockPreviewHint
    {
        throw new \RuntimeException('stored data of unknown age');
    }
}
