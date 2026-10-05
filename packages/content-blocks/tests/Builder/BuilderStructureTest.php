<?php

declare(strict_types=1);

namespace ContentBlocks\Tests\Builder;

use ContentBlocks\Builder\BuilderStructure;
use ContentBlocks\Builder\ConfiguredBuilderStructureResolver;
use ContentBlocks\Entity\ContentArea;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BuilderStructureTest extends TestCase
{
    /**
     * @return iterable<string, array{BuilderStructure, bool, bool, bool}>
     */
    public static function structures(): iterable
    {
        yield 'the default' => [new BuilderStructure(), true, true, true];
        yield 'fixed sections' => [new BuilderStructure(BuilderStructure::SECTIONS_FIXED), false, true, true];
        yield 'fixed, columns locked' => [
            new BuilderStructure(BuilderStructure::SECTIONS_FIXED, false), false, true, false,
        ];
        // Columns live in the section sidebar, which hidden sections lose.
        yield 'hidden sections' => [new BuilderStructure(BuilderStructure::SECTIONS_HIDDEN), false, false, false];
        yield 'columns locked' => [new BuilderStructure(columns: false), true, true, false];
    }

    #[DataProvider('structures')]
    public function testWhatEachStructureAllows(
        BuilderStructure $structure,
        bool $editSections,
        bool $showSections,
        bool $editColumns,
    ): void {
        $this->assertSame($editSections, $structure->canEditSections());
        $this->assertSame($showSections, $structure->showsSections());
        $this->assertSame($editColumns, $structure->canEditColumns());
        $this->assertSame(
            ['sections' => $structure->sections, 'columns' => $editColumns],
            $structure->toArray(),
        );
    }

    public function testAnUnknownModeIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new BuilderStructure('frozen');
    }

    public function testTheConfiguredResolverAnswersTheSameForEveryArea(): void
    {
        $resolver = new ConfiguredBuilderStructureResolver(BuilderStructure::SECTIONS_HIDDEN, false);

        $structure = $resolver->forArea(new ContentArea());

        $this->assertSame(BuilderStructure::SECTIONS_HIDDEN, $structure->sections);
        $this->assertSame($structure, $resolver->forArea(new ContentArea()));
    }
}
