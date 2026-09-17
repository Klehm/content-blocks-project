<?php

declare(strict_types=1);

namespace ContentBlocks\Tests\Block;

use ContentBlocks\Block\CollectionIdBackfiller;
use ContentBlocks\BlockType\AbstractBlockType;
use ContentBlocks\BlockType\BlockTypeRegistry;
use ContentBlocks\Form\Extension\BlockFormExtensionCollection;
use ContentBlocks\Form\Type\BlockFormType;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\Forms;

final class CollectionIdBackfillerTest extends TestCase
{
    /** A table as the kit stores it: rows of cells, both collections. */
    public function testEveryEntryGetsAnIdDownToNestedCollections(): void
    {
        $data = $this->backfiller()->backfill('grid', (new GridFixtureBlock())->getDefaultData());

        $this->assertIsString($data['rows'][0]['_id']);
        $this->assertIsString($data['rows'][0]['cells'][0]['_id']);
        $this->assertIsString($data['rows'][0]['cells'][1]['_id']);
        $this->assertNotSame($data['rows'][0]['cells'][0]['_id'], $data['rows'][0]['cells'][1]['_id']);
        $this->assertSame('Row 1', $data['rows'][0]['cells'][0]['content']);
    }

    /** Imported translations are keyed on the ids the export carried. */
    public function testIdsAlreadyThereAreKept(): void
    {
        $data = ['rows' => [['_id' => 'r1', 'cells' => [['_id' => 'c1', 'content' => 'A'], ['content' => 'B']]]]];

        $filled = $this->backfiller()->backfill('grid', $data);

        $this->assertSame('r1', $filled['rows'][0]['_id']);
        $this->assertSame('c1', $filled['rows'][0]['cells'][0]['_id']);
        $this->assertIsString($filled['rows'][0]['cells'][1]['_id']);
    }

    public function testAnUnknownTypeIsLeftAsIs(): void
    {
        $data = ['rows' => [['cells' => []]]];

        $this->assertSame($data, $this->backfiller()->backfill('gone', $data));
    }

    public static function registry(): BlockTypeRegistry
    {
        $registry = new BlockTypeRegistry();
        $registry->register(new GridFixtureBlock());

        return $registry;
    }

    public static function backfillerFor(BlockTypeRegistry $registry): CollectionIdBackfiller
    {
        $factory = Forms::createFormFactoryBuilder()
            ->addType(new BlockFormType(new BlockFormExtensionCollection()))
            ->getFormFactory();

        return new CollectionIdBackfiller($registry, $factory);
    }

    private function backfiller(): CollectionIdBackfiller
    {
        return self::backfillerFor(self::registry());
    }
}

final class GridFixtureBlock extends AbstractBlockType
{
    public static function getType(): string
    {
        return 'grid';
    }

    public static function getLabel(): string
    {
        return 'Grid';
    }

    public function buildForm(FormBuilderInterface $builder, array $data): void
    {
        $builder->add('rows', CollectionType::class, [
            'entry_type' => GridRowFixtureType::class,
            'allow_add' => true,
        ]);
    }

    public function getDefaultData(): array
    {
        return ['rows' => [['cells' => [['content' => 'Row 1'], ['content' => '—']]]]];
    }
}

final class GridRowFixtureType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('cells', CollectionType::class, [
            'entry_type' => GridCellFixtureType::class,
            'allow_add' => true,
        ]);
    }
}

final class GridCellFixtureType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('content', TextType::class, ['required' => false]);
    }
}
