<?php

declare(strict_types=1);

namespace ContentBlocks\Tests\Snapshot;

use ContentBlocks\BlockType\AbstractBlockType;
use ContentBlocks\BlockType\BlockTypeRegistry;
use ContentBlocks\Entity\Block;
use ContentBlocks\Entity\Column;
use ContentBlocks\Entity\Section;
use ContentBlocks\Snapshot\SnapshotExtensionInterface;
use ContentBlocks\Snapshot\SnapshotExtensions;
use ContentBlocks\Tests\Fixtures\RecordingSnapshotExtension;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Form\FormBuilderInterface;

/**
 * The refs are positions, so everything rests on capture and restore walking
 * the same way — and on restore stepping past what a paste skipped.
 */
final class SnapshotExtensionsTest extends TestCase
{
    public function testRefsFollowTheLiveOrderAndSkipDeletedEntities(): void
    {
        $section = new Section();
        $second = $this->column($section, 1, 'col-6');
        $first = $this->column($section, 0, 'col-6');
        $this->column($section, 2, 'col-12')->setDeleted(true);
        $this->block($first, 1, 'B');
        $this->block($first, 0, 'A');
        $this->block($first, 2, 'gone')->setDeleted(true);
        $this->block($second, 0, 'C');

        $captured = $this->extensions()->captureSection($section);

        $this->assertSame([
            'acme/recording' => [
                'blocks' => ['c0.b0' => 'A', 'c0.b1' => 'B', 'c1.b0' => 'C'],
                'columns' => ['c0' => 'col-6', 'c1' => 'col-6'],
            ],
        ], $captured);
    }

    public function testAnExtensionWithNothingToCarryLeavesNoKey(): void
    {
        $quiet = new class () implements SnapshotExtensionInterface {
            public function key(): string
            {
                return 'acme/quiet';
            }

            public function capture(array $blocks, array $columns): array
            {
                return [];
            }

            public function restore(array $blocks, array $columns, array $fragment): void
            {
            }
        };

        $block = new Block();
        $block->setType(SnapshotFixtureBlock::TYPE);

        $this->assertSame([], (new SnapshotExtensions([$quiet]))->captureBlock($block));
    }

    // A block whose type is gone was not built: `c0.b1` is the second copy.
    public function testRestoreStepsPastASkippedBlockAndAMalformedColumn(): void
    {
        $recording = new RecordingSnapshotExtension();
        $section = new Section();
        $copyColumn = $this->column($section, 0, 'col-12');
        $a = $this->block($copyColumn, 0, 'A');
        $c = $this->block($copyColumn, 1, 'C');

        $payload = [
            'columns' => [
                'not a column',
                ['blocks' => [
                    ['type' => SnapshotFixtureBlock::TYPE],
                    ['type' => 'gone_type'],
                    ['type' => SnapshotFixtureBlock::TYPE],
                ]],
            ],
            SnapshotExtensions::PAYLOAD_KEY => ['acme/recording' => ['anything' => true]],
        ];

        $this->extensions($recording)->restoreSection($section, $payload);

        $this->assertCount(1, $recording->restored);
        $this->assertSame(['c1.b0' => $a, 'c1.b2' => $c], $recording->restored[0]['blocks']);
        $this->assertSame(['c1' => $copyColumn], $recording->restored[0]['columns']);
        $this->assertSame(['anything' => true], $recording->restored[0]['fragment']);
    }

    public function testNothingIsRestoredWithoutTheKey(): void
    {
        $recording = new RecordingSnapshotExtension();
        $block = new Block();

        $this->extensions($recording)->restoreBlock($block, ['type' => SnapshotFixtureBlock::TYPE]);
        $this->extensions($recording)->restoreBlock($block, [SnapshotExtensions::PAYLOAD_KEY => 'junk']);

        $this->assertSame([], $recording->restored);
    }

    public function testABlockCopiedAloneIsAddressedAsB(): void
    {
        $recording = new RecordingSnapshotExtension();
        $block = new Block();
        $block->setType(SnapshotFixtureBlock::TYPE);

        $this->extensions($recording)->restoreBlock($block, [
            SnapshotExtensions::PAYLOAD_KEY => ['acme/recording' => ['blocks' => ['b' => 'x']]],
        ]);

        $this->assertSame([SnapshotExtensions::BLOCK_REF => $block], $recording->restored[0]['blocks']);
    }

    private function extensions(?RecordingSnapshotExtension $recording = null): SnapshotExtensions
    {
        $registry = new BlockTypeRegistry();
        $registry->register(new SnapshotFixtureBlock());

        return new SnapshotExtensions([$recording ?? new RecordingSnapshotExtension()], $registry);
    }

    private function column(Section $section, int $position, string $preset): Column
    {
        $column = new Column();
        $column->setPreset($preset);
        $column->setPreviewPosition($position);
        $section->addColumn($column);

        return $column;
    }

    private function block(Column $column, int $position, string $title): Block
    {
        $block = new Block();
        $block->setType(SnapshotFixtureBlock::TYPE);
        $block->setDraftData(['title' => $title]);
        $block->setPreviewPosition($position);
        $column->addBlock($block);

        return $block;
    }
}

final class SnapshotFixtureBlock extends AbstractBlockType
{
    public const TYPE = 'snapshot_fixture';

    public function getType(): string
    {
        return self::TYPE;
    }

    public function getLabel(): string
    {
        return 'Snapshot fixture';
    }

    public function buildForm(FormBuilderInterface $builder, array $data): void
    {
    }

    public function getDefaultData(): array
    {
        return ['title' => ''];
    }
}
