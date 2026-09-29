<?php

declare(strict_types=1);

namespace ContentBlocks\Tests\Publishing;

use ContentBlocks\Entity\Block;
use ContentBlocks\Entity\Column;
use ContentBlocks\Entity\ContentArea;
use ContentBlocks\Entity\Section;
use ContentBlocks\Publishing\UnpublishedChanges;
use ContentBlocks\Publishing\UnpublishedChangesProviderInterface;
use PHPUnit\Framework\TestCase;

final class UnpublishedChangesTest extends TestCase
{
    public function testWithNoProviderItIsTheAreasOwnState(): void
    {
        $this->assertFalse((new UnpublishedChanges())->of(new ContentArea()));
        $this->assertTrue((new UnpublishedChanges())->of($this->dirtyArea()));
    }

    public function testAProviderCanReportADraftTheAreaDoesNotSee(): void
    {
        $changes = new UnpublishedChanges([$this->provider(false), $this->provider(true)]);

        $this->assertTrue($changes->of(new ContentArea()));
    }

    // The area's own draft is enough; providers may cost a query each.
    public function testProvidersAreNotAskedWhenTheAreaIsDirty(): void
    {
        $asked = $this->createMock(UnpublishedChangesProviderInterface::class);
        $asked->expects($this->never())->method('hasUnpublishedChanges');

        $this->assertTrue((new UnpublishedChanges([$asked]))->of($this->dirtyArea()));
    }

    private function provider(bool $answer): UnpublishedChangesProviderInterface
    {
        $provider = $this->createStub(UnpublishedChangesProviderInterface::class);
        $provider->method('hasUnpublishedChanges')->willReturn($answer);

        return $provider;
    }

    private function dirtyArea(): ContentArea
    {
        $area = new ContentArea();
        $section = new Section();
        $column = new Column();
        $block = new Block();
        $block->setType('text');
        $block->setDraftData(['content' => 'wip']);
        $column->addBlock($block);
        $section->addColumn($column);
        $area->addSection($section);

        return $area;
    }
}
