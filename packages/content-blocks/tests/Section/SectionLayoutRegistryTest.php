<?php

declare(strict_types=1);

namespace ContentBlocks\Tests\Section;

use ContentBlocks\Section\SectionLayout;
use ContentBlocks\Section\SectionLayoutRegistry;
use PHPUnit\Framework\TestCase;

final class SectionLayoutRegistryTest extends TestCase
{
    public function testWithNoConfigTheThreeBuiltInsAreOffered(): void
    {
        $registry = new SectionLayoutRegistry();

        $this->assertSame(['full', 'two_cols', 'three_cols'], $this->names($registry->all()));
        $this->assertSame(['col-4', 'col-4', 'col-4'], $registry->creatable('three_cols')?->presets());
        $this->assertSame('cb.section.layout.two_cols', $registry->get('two_cols')?->label);
    }

    public function testAHostLayoutIsAppendedAfterTheBuiltIns(): void
    {
        $registry = new SectionLayoutRegistry(SectionLayoutRegistry::resolve([
            'four_cols' => ['label' => '4 columns', 'columns' => [3, 3, 3, 3]],
            'sidebar_left' => ['label' => 'Sidebar', 'columns' => [4, 8]],
        ]));

        $this->assertSame(
            ['full', 'two_cols', 'three_cols', 'four_cols', 'sidebar_left'],
            $this->names($registry->all()),
        );
        $this->assertSame(['col-3', 'col-3', 'col-3', 'col-3'], $registry->creatable('four_cols')?->presets());
        $this->assertSame(['col-4', 'col-8'], $registry->creatable('sidebar_left')?->presets());
    }

    public function testABuiltInIsOverriddenInPlaceFieldByField(): void
    {
        $registry = new SectionLayoutRegistry(SectionLayoutRegistry::resolve([
            'two_cols' => ['label' => 'Halves'],
        ]));

        $layout = $registry->get('two_cols');
        $this->assertSame('Halves', $layout?->label);
        $this->assertSame([6, 6], $layout?->columns);
        $this->assertSame(['full', 'two_cols', 'three_cols'], $this->names($registry->all()));
    }

    /** Hiding a layout must not orphan the sections already built with it. */
    public function testADisabledLayoutIsNotCreatableButKeepsItsLabel(): void
    {
        $registry = new SectionLayoutRegistry(SectionLayoutRegistry::resolve([
            'three_cols' => ['enabled' => false],
        ]));

        $this->assertSame(['full', 'two_cols'], $this->names($registry->all()));
        $this->assertNull($registry->creatable('three_cols'));
        $this->assertSame('cb.section.layout.three_cols', $registry->get('three_cols')?->label);
    }

    public function testALayoutCanStartItsSectionsAsTabs(): void
    {
        $registry = new SectionLayoutRegistry(SectionLayoutRegistry::resolve([
            'tabs' => ['label' => 'Tabs', 'columns' => [4, 4, 4], 'display' => 'tabs'],
            'two_cols' => ['display' => 'tabs'],
        ]));

        $this->assertSame('tabs', $registry->get('tabs')?->display);
        $this->assertSame('tabs', $registry->get('two_cols')?->display);
        $this->assertSame('grid', $registry->get('full')?->display);
    }

    public function testAnUnknownLayoutIsNotCreatable(): void
    {
        $this->assertNull((new SectionLayoutRegistry())->creatable('six_cols'));
        $this->assertNull((new SectionLayoutRegistry())->get('six_cols'));
    }

    public function testANewLayoutWithoutColumnsIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('four_cols');

        SectionLayoutRegistry::resolve(['four_cols' => ['label' => '4 columns', 'columns' => []]]);
    }

    public function testANewLayoutWithoutLabelIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        SectionLayoutRegistry::resolve(['four_cols' => ['label' => null, 'columns' => [3, 3, 3, 3]]]);
    }

    /**
     * @param list<SectionLayout> $layouts
     *
     * @return list<string>
     */
    private function names(array $layouts): array
    {
        return array_map(static fn (SectionLayout $l): string => $l->name, $layouts);
    }
}
