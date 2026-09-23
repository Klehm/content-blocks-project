<?php

declare(strict_types=1);

namespace ContentBlocks\Kit\Tests\Block;

use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

final class AccordionViewTest extends TestCase
{
    public function testEachItemIsANativeDetailsElement(): void
    {
        $html = $this->render(['items' => $this->items(3)], 1);

        $this->assertSame(3, substr_count($html, '<details class="cb-kit-accordion__item"'));
        $this->assertSame(3, substr_count($html, '<summary'));
    }

    public function testItemsWithoutATitleAreLeftOut(): void
    {
        $items = [...$this->items(2), ['title' => '  ', 'content' => 'Orphan']];
        $html = $this->render(['items' => $items], 1);

        $this->assertSame(2, substr_count($html, '<details'));
        $this->assertStringNotContainsString('Orphan', $html);
    }

    public function testNoItemRendersNothing(): void
    {
        $this->assertSame('', trim($this->render(['items' => []], 1)));
    }

    public function testPanelsAreIndependentUnlessExclusive(): void
    {
        $html = $this->render(['items' => $this->items(2)], 1);

        $this->assertStringNotContainsString('name=', $html);
    }

    // Two single-open accordions with as many items shared one `name`, so
    // opening a panel in one closed the other.
    public function testTwoExclusiveAccordionsDoNotShareAGroup(): void
    {
        $first = $this->render(['items' => $this->items(2), 'exclusive' => true], 7);
        $second = $this->render(['items' => $this->items(2), 'exclusive' => true], 8);

        preg_match('/name="([^"]+)"/', $first, $a);
        preg_match('/name="([^"]+)"/', $second, $b);

        $this->assertSame('cb-acc-7', $a[1]);
        $this->assertNotSame($a[1], $b[1]);
    }

    public function testExclusivePanelsOfOneBlockShareOneGroup(): void
    {
        $html = $this->render(['items' => $this->items(3), 'exclusive' => true], 5);

        $this->assertSame(3, substr_count($html, 'name="cb-acc-5"'));
    }

    public function testTheSameBlockRendersTheSameMarkupTwice(): void
    {
        $data = ['items' => $this->items(2), 'exclusive' => true];

        $this->assertSame($this->render($data, 9), $this->render($data, 9));
    }

    public function testContentIsEscaped(): void
    {
        $items = [['title' => '<b>T</b>', 'content' => "<script>x</script>\nline"]];
        $html = $this->render(['items' => $items], 1);

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringNotContainsString('<b>T</b>', $html);
        $this->assertStringContainsString('<br />', $html);
    }

    /** @return list<array{title: string, content: string}> */
    private function items(int $count): array
    {
        $items = [];
        for ($i = 1; $i <= $count; ++$i) {
            $items[] = ['title' => 'Title ' . $i, 'content' => 'Content ' . $i];
        }

        return $items;
    }

    /** @param array<string, mixed> $data */
    private function render(array $data, ?int $blockId = null): string
    {
        $loader = new FilesystemLoader();
        $loader->addPath(\dirname(__DIR__, 2) . '/templates', 'ContentBlocksKit');
        $twig = new Environment($loader, ['strict_variables' => true]);
        $twig->addFunction(new \Twig\TwigFunction('cb_kit_icon', static fn () => '<svg></svg>'));

        return $twig->render(
            '@ContentBlocksKit/block/accordion/view.html.twig',
            ['data' => $data, 'block_id' => $blockId],
        );
    }
}
