<?php

declare(strict_types=1);

namespace ContentBlocks\Tests\Test;

use ContentBlocks\Entity\Section;
use ContentBlocks\Test\BlockBuilder;
use ContentBlocks\Test\ColumnBuilder;
use ContentBlocks\Test\ContentAreaBuilder;
use ContentBlocks\Test\SectionBuilder;
use PHPUnit\Framework\TestCase;

final class ContentAreaBuilderTest extends TestCase
{
    public function testABuiltTreeIsPublishedByDefault(): void
    {
        $area = ContentAreaBuilder::create()
            ->section(fn (SectionBuilder $s) => $s
                ->column(fn (ColumnBuilder $c) => $c->block('text', ['content' => 'hello'])))
            ->build();

        $section = $area->getSections()->first();
        $column = $section->getColumns()->first();
        $block = $column->getBlocks()->first();

        $this->assertTrue($section->isPublished());
        $this->assertTrue($column->isPublished());
        $this->assertSame(['content' => 'hello'], $block->getPublishedData());
        $this->assertNull($block->getDraftData(), 'a published fixture leaves nothing on the draft side');
        $this->assertFalse(
            $area->hasUnpublishedChanges(),
            'the default has to be a page that actually renders in front-office',
        );
    }

    public function testDraftLeavesTheWholeSubtreeUnpublished(): void
    {
        $area = ContentAreaBuilder::create()
            ->draft()
            ->section(fn (SectionBuilder $s) => $s
                ->settings(['backgroundColor' => '#fff'])
                ->column(fn (ColumnBuilder $c) => $c->block('text', ['content' => 'hello'])))
            ->build();

        $section = $area->getSections()->first();
        $column = $section->getColumns()->first();
        $block = $column->getBlocks()->first();

        $this->assertFalse($section->isPublished());
        $this->assertFalse($column->isPublished());
        $this->assertSame(['backgroundColor' => '#fff'], $section->getDraftSettings());
        $this->assertNull($section->getPublishedSettings());
        $this->assertSame(['content' => 'hello'], $block->getDraftData());
        $this->assertNull($block->getPublishedData());
        $this->assertTrue($area->hasUnpublishedChanges());
    }

    public function testSettingsArePromotedOnAPublishedSection(): void
    {
        $area = ContentAreaBuilder::create()
            ->section(fn (SectionBuilder $s) => $s->settings(['backgroundColor' => '#eb0540']))
            ->build();

        $section = $area->getSections()->first();

        $this->assertSame(['backgroundColor' => '#eb0540'], $section->getPublishedSettings());
        $this->assertNull($section->getDraftSettings());
    }

    public function testPositionsAutoIncrementInInsertionOrder(): void
    {
        $area = ContentAreaBuilder::create()
            ->section(fn (SectionBuilder $s) => $s
                ->column(fn (ColumnBuilder $c) => $c
                    ->block('text', ['content' => 'first'])
                    ->block('text', ['content' => 'second'])))
            ->section()
            ->build();

        $sections = $area->getSections()->toArray();
        $this->assertSame([0, 1], array_map(fn (Section $s) => $s->getPosition(), $sections));

        $blocks = $sections[0]->getColumns()->first()->getBlocks()->toArray();
        $this->assertSame([0, 1], array_map(fn ($b) => $b->getPosition(), $blocks));
        $this->assertSame([0, 1], array_map(fn ($b) => $b->getPreviewPosition(), $blocks));
    }

    public function testAnExplicitPositionSurvivesPublication(): void
    {
        // publish() syncs position from previewPosition; an ordering asked for
        // explicitly has to win over that.
        $area = ContentAreaBuilder::create()
            ->section(fn (SectionBuilder $s) => $s->position(3, 5))
            ->build();

        $section = $area->getSections()->first();

        $this->assertSame(3, $section->getPosition());
        $this->assertSame(5, $section->getPreviewPosition());
        $this->assertTrue($section->hasUnpublishedChanges(), 'a pending reorder is exactly this mismatch');
    }

    public function testExplicitSidesAreBothKept(): void
    {
        $area = ContentAreaBuilder::create()
            ->section(fn (SectionBuilder $s) => $s
                ->column(fn (ColumnBuilder $c) => $c->block(
                    'text',
                    configure: fn (BlockBuilder $b) => $b
                        ->publishedData(['content' => 'live'])
                        ->draftData(['content' => 'edited']),
                )))
            ->build();

        $block = $area->getSections()->first()->getColumns()->first()->getBlocks()->first();

        $this->assertSame(['content' => 'live'], $block->getPublishedData());
        $this->assertSame(['content' => 'edited'], $block->getDraftData());
    }

    public function testANodeCanOptBackIntoPublicationInsideADraftArea(): void
    {
        $area = ContentAreaBuilder::create()
            ->draft()
            ->section(fn (SectionBuilder $s) => $s->published()
                ->column(fn (ColumnBuilder $c) => $c->block('text', ['content' => 'hello'])))
            ->build();

        $section = $area->getSections()->first();

        $this->assertTrue($section->isPublished());
        $this->assertTrue(
            $section->getColumns()->first()->isPublished(),
            'the override propagates down, like the area-level default does',
        );
    }

    public function testTheOrderOfCallsInsideTheClosureDoesNotMatter(): void
    {
        $childFirst = ContentAreaBuilder::create()
            ->section(fn (SectionBuilder $s) => $s
                ->column(fn (ColumnBuilder $c) => $c->block('text', ['content' => 'hello']))
                ->draft())
            ->build();

        $draftFirst = ContentAreaBuilder::create()
            ->section(fn (SectionBuilder $s) => $s
                ->draft()
                ->column(fn (ColumnBuilder $c) => $c->block('text', ['content' => 'hello'])))
            ->build();

        foreach ([$childFirst, $draftFirst] as $area) {
            $block = $area->getSections()->first()->getColumns()->first()->getBlocks()->first();
            $this->assertSame(['content' => 'hello'], $block->getDraftData());
            $this->assertNull($block->getPublishedData());
        }
    }

    public function testBuildingTwiceYieldsIndependentTrees(): void
    {
        $builder = ContentAreaBuilder::create()
            ->section(fn (SectionBuilder $s) => $s->column());

        $first = $builder->build();
        $second = $builder->build();

        $this->assertNotSame($first, $second);
        $this->assertCount(1, $first->getSections());
        $this->assertCount(1, $second->getSections(), 'a second build must not append to the first tree');
        $this->assertNotSame($first->getSections()->first(), $second->getSections()->first());
    }

    public function testIdsAreStampedAtEveryLevel(): void
    {
        $area = ContentAreaBuilder::create()
            ->withId(7)
            ->section(fn (SectionBuilder $s) => $s
                ->withId(11)
                ->column(fn (ColumnBuilder $c) => $c
                    ->withId(13)
                    ->block('text', configure: fn (BlockBuilder $b) => $b->withId(17))))
            ->build();

        $section = $area->getSections()->first();
        $column = $section->getColumns()->first();

        $this->assertSame(7, $area->getId());
        $this->assertSame(11, $section->getId());
        $this->assertSame(13, $column->getId());
        $this->assertSame(17, $column->getBlocks()->first()->getId());
    }

    public function testLayoutPresetAndDeletionAreCarriedThrough(): void
    {
        $area = ContentAreaBuilder::create()
            ->updatedAt(new \DateTimeImmutable('2026-01-02 03:04:05'))
            ->contentVersion(4)
            ->section(fn (SectionBuilder $s) => $s
                ->layout(Section::LAYOUT_TWO_COLS)
                ->column(fn (ColumnBuilder $c) => $c
                    ->preset('col-6')
                    ->block('text', configure: fn (BlockBuilder $b) => $b->deleted()))
                ->column(fn (ColumnBuilder $c) => $c->preset('col-6')->deleted()))
            ->build();

        $section = $area->getSections()->first();
        $columns = $section->getColumns()->toArray();

        $this->assertSame('2026-01-02 03:04:05', $area->getUpdatedAt()?->format('Y-m-d H:i:s'));
        $this->assertSame(4, $area->getContentVersion());
        $this->assertSame(Section::LAYOUT_TWO_COLS, $section->getLayout());
        $this->assertSame('col-6', $columns[0]->getPreset());
        $this->assertTrue($columns[0]->getBlocks()->first()->isDeleted());
        $this->assertTrue($columns[1]->isDeleted());
        $this->assertFalse($columns[0]->isDeleted());
    }

    public function testAnEmptyAreaIsValid(): void
    {
        $area = ContentAreaBuilder::create()->withId(3)->build();

        $this->assertSame(3, $area->getId());
        $this->assertCount(0, $area->getSections());
        $this->assertFalse($area->hasUnpublishedChanges());
    }
}
