<?php

declare(strict_types=1);

namespace ContentBlocks\Kit\Tests\Block;

use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

final class TableViewTest extends TestCase
{
    public function testOneHeaderCellPerColumn(): void
    {
        $html = $this->render($this->table());

        $this->assertSame(2, substr_count($html, '<th scope="col"'));
        $this->assertStringContainsString('>Name</th>', $html);
    }

    // Cells map to columns by position: a short row is padded, a long one cut.
    public function testTheTableStaysRectangular(): void
    {
        $data = $this->table();
        $data['rows'] = [
            ['cells' => [['content' => 'only one']]],
            ['cells' => [['content' => 'a'], ['content' => 'b'], ['content' => 'extra']]],
        ];
        $html = $this->render($data);

        $this->assertSame(4, substr_count($html, '<td'));
        $this->assertStringNotContainsString('extra', $html);
    }

    public function testColumnAlignmentReachesItsCells(): void
    {
        $html = $this->render($this->table());

        $this->assertSame(2, substr_count($html, 'cb-kit-table--r'));
        $this->assertSame(2, substr_count($html, 'cb-kit-table--l'));
    }

    public function testAnUnknownAlignmentFallsBackToStart(): void
    {
        $data = $this->table();
        $data['columns'][1]['align'] = 'diagonal';

        $this->assertStringNotContainsString('diagonal', $this->render($data));
    }

    public function testStripedIsAModifier(): void
    {
        $this->assertStringContainsString('cb-kit-table--striped', $this->render($this->table()));

        $data = $this->table();
        $data['striped'] = false;
        $this->assertStringNotContainsString('--striped', $this->render($data));
    }

    public function testCellContentIsEscapedAndKeepsItsLineBreaks(): void
    {
        $data = $this->table();
        $data['rows'] = [['cells' => [['content' => "<i>x</i>\ny"], ['content' => '']]]];
        $html = $this->render($data);

        $this->assertStringNotContainsString('<i>x</i>', $html);
        $this->assertStringContainsString('<br />', $html);
    }

    public function testNoColumnRendersNothing(): void
    {
        $this->assertSame('', trim($this->render(['columns' => [], 'rows' => []])));
    }

    /** @return array<string, mixed> */
    private function table(): array
    {
        return [
            'striped' => true,
            'columns' => [
                ['label' => 'Name', 'align' => 'start'],
                ['label' => 'Value', 'align' => 'end'],
            ],
            'rows' => [
                ['cells' => [['content' => 'A'], ['content' => '1']]],
            ],
        ];
    }

    /** @param array<string, mixed> $data */
    private function render(array $data): string
    {
        $loader = new FilesystemLoader();
        $loader->addPath(\dirname(__DIR__, 2) . '/templates', 'ContentBlocksKit');

        return (new Environment($loader, ['strict_variables' => true]))->render(
            '@ContentBlocksKit/block/table/view.html.twig',
            ['data' => $data, 'block_id' => 1],
        );
    }
}
