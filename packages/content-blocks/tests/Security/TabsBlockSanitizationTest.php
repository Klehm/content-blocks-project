<?php

declare(strict_types=1);

namespace ContentBlocks\Tests\Security;

use ContentBlocks\Kit\Block\TabsBlock;
use PHPUnit\Framework\TestCase;

final class TabsBlockSanitizationTest extends TestCase
{
    private TabsBlock $block;

    protected function setUp(): void
    {
        $this->block = new TabsBlock();
    }

    public function testValidTabsPassThrough(): void
    {
        $data = [
            'tabs' => [
                ['title' => 'Tab 1', 'content' => 'Content 1'],
                ['title' => 'Tab 2', 'content' => 'Content 2'],
            ],
        ];

        $result = $this->block->sanitizeData($data);

        $this->assertCount(2, $result['tabs']);
        $this->assertSame('Tab 1', $result['tabs'][0]['title']);
        $this->assertSame('Content 2', $result['tabs'][1]['content']);
    }

    public function testExtraKeysInTabAreStripped(): void
    {
        $data = [
            'tabs' => [
                ['title' => 'Tab', 'content' => 'Text', 'malicious' => '<script>'],
            ],
        ];

        $result = $this->block->sanitizeData($data);

        $this->assertArrayNotHasKey('malicious', $result['tabs'][0]);
        $this->assertSame(['title', 'content'], array_keys($result['tabs'][0]));
    }

    public function testExtraTopLevelKeysAreIgnored(): void
    {
        $data = [
            'tabs' => [['title' => 'Tab', 'content' => '']],
            'injection' => 'payload',
        ];

        $result = $this->block->sanitizeData($data);

        $this->assertArrayNotHasKey('injection', $result);
        $this->assertSame(['tabs'], array_keys($result));
    }

    public function testMissingTabsReturnDefault(): void
    {
        $result = $this->block->sanitizeData([]);

        $this->assertCount(1, $result['tabs']);
        $this->assertSame('Tab 1', $result['tabs'][0]['title']);
    }

    public function testNonArrayTabsReturnDefault(): void
    {
        $result = $this->block->sanitizeData(['tabs' => 'not_an_array']);

        $this->assertCount(1, $result['tabs']);
    }

    public function testEmptyTabsArrayReturnDefault(): void
    {
        $result = $this->block->sanitizeData(['tabs' => []]);

        $this->assertCount(1, $result['tabs']);
    }

    public function testNonArrayTabItemsAreSkipped(): void
    {
        $data = [
            'tabs' => [
                'not_a_tab',
                ['title' => 'Valid', 'content' => 'OK'],
                42,
            ],
        ];

        $result = $this->block->sanitizeData($data);

        $this->assertCount(1, $result['tabs']);
        $this->assertSame('Valid', $result['tabs'][0]['title']);
    }

    public function testMissingTabFieldsGetDefaults(): void
    {
        $data = [
            'tabs' => [
                ['title' => 'Only title'],
                ['content' => 'Only content'],
                [],
            ],
        ];

        $result = $this->block->sanitizeData($data);

        $this->assertSame('Only title', $result['tabs'][0]['title']);
        $this->assertSame('', $result['tabs'][0]['content']);
        $this->assertSame('', $result['tabs'][1]['title']);
        $this->assertSame('Only content', $result['tabs'][1]['content']);
        $this->assertSame('', $result['tabs'][2]['title']);
    }

    public function testTitleIsTruncatedAt255Chars(): void
    {
        $longTitle = str_repeat('A', 300);
        $data = [
            'tabs' => [['title' => $longTitle, 'content' => '']],
        ];

        $result = $this->block->sanitizeData($data);

        $this->assertSame(255, mb_strlen($result['tabs'][0]['title']));
    }

    public function testMaxTabsLimit(): void
    {
        $tabs = array_fill(0, 25, ['title' => 'Tab', 'content' => '']);
        $data = ['tabs' => $tabs];

        $result = $this->block->sanitizeData($data);

        $this->assertCount(20, $result['tabs']);
    }

    // --- processData tests (JSON string decoding from LiveComponent) ---

    public function testProcessDataDecodesJsonString(): void
    {
        $json = json_encode([
            ['title' => 'Tab 1', 'content' => 'Hello'],
            ['title' => 'Tab 2', 'content' => 'World'],
        ]);

        $result = $this->block->processData(['tabs' => $json]);

        $this->assertIsArray($result['tabs']);
        $this->assertCount(2, $result['tabs']);
        $this->assertSame('Tab 1', $result['tabs'][0]['title']);
    }

    public function testProcessDataPassesThroughArrayTabs(): void
    {
        $tabs = [['title' => 'Tab', 'content' => 'OK']];

        $result = $this->block->processData(['tabs' => $tabs]);

        $this->assertSame($tabs, $result['tabs']);
    }

    public function testProcessDataHandlesInvalidJsonString(): void
    {
        $result = $this->block->processData(['tabs' => 'not valid json']);

        $this->assertSame([], $result['tabs']);
    }

    public function testProcessDataHandlesEmptyJsonArray(): void
    {
        $result = $this->block->processData(['tabs' => '[]']);

        $this->assertSame([], $result['tabs']);
    }

    public function testFullFlowJsonStringThroughProcessAndSanitize(): void
    {
        $json = json_encode([
            ['title' => 'Valid', 'content' => 'Text', 'evil' => 'injected'],
            'not_a_tab',
            ['title' => 'Also Valid', 'content' => ''],
        ]);

        $processed = $this->block->processData(['tabs' => $json]);
        $sanitized = $this->block->sanitizeData($processed);

        $this->assertCount(2, $sanitized['tabs']);
        $this->assertSame('Valid', $sanitized['tabs'][0]['title']);
        $this->assertArrayNotHasKey('evil', $sanitized['tabs'][0]);
        $this->assertSame('Also Valid', $sanitized['tabs'][1]['title']);
    }

    public function testNonStringValuesAreCastToEmptyString(): void
    {
        $data = [
            'tabs' => [
                ['title' => 123, 'content' => ['nested' => 'array']],
            ],
        ];

        $result = $this->block->sanitizeData($data);

        $this->assertSame('', $result['tabs'][0]['title']);
        $this->assertSame('', $result['tabs'][0]['content']);
    }
}
