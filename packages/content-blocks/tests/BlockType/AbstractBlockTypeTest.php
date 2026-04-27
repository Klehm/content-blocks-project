<?php

declare(strict_types=1);

namespace ContentBlocks\Tests\BlockType;

use ContentBlocks\BlockType\AbstractBlockType;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Form\FormBuilderInterface;

final class AbstractBlockTypeTest extends TestCase
{
    public function testGetAllowedDataKeysFromDefaultData(): void
    {
        $block = $this->createSimpleBlock(['title' => '', 'body' => '']);

        $this->assertSame(['title', 'body'], $block->getAllowedDataKeys());
    }

    public function testSanitizeDataStripsUnknownKeys(): void
    {
        $block = $this->createSimpleBlock(['title' => '', 'body' => '']);

        $result = $block->sanitizeData([
            'title' => 'Hello',
            'body' => 'World',
            'malicious' => '<script>alert(1)</script>',
        ]);

        $this->assertSame(['title' => 'Hello', 'body' => 'World'], $result);
    }

    public function testSanitizeDataCastsToString(): void
    {
        $block = $this->createSimpleBlock(['count' => '']);

        $result = $block->sanitizeData(['count' => 42]);

        $this->assertSame(['count' => '42'], $result);
    }

    public function testSanitizeDataIgnoresMissingKeys(): void
    {
        $block = $this->createSimpleBlock(['title' => '', 'body' => '']);

        $result = $block->sanitizeData(['title' => 'Only title']);

        $this->assertSame(['title' => 'Only title'], $result);
        $this->assertArrayNotHasKey('body', $result);
    }

    public function testProcessDataReturnsDataAsIs(): void
    {
        $block = $this->createSimpleBlock(['foo' => '']);

        $data = ['foo' => 'bar', 'extra' => 'baz'];
        $this->assertSame($data, $block->processData($data));
    }

    public function testGetEditTemplateReturnsNull(): void
    {
        $block = $this->createSimpleBlock([]);
        $this->assertNull($block->getEditTemplate());
    }

    public function testGetViewTemplateReturnsNull(): void
    {
        $block = $this->createSimpleBlock([]);
        $this->assertNull($block->getViewTemplate());
    }

    private function createSimpleBlock(array $defaults): AbstractBlockType
    {
        return new class($defaults) extends AbstractBlockType {
            public function __construct(private readonly array $defaults) {}
            public static function getType(): string { return 'test'; }
            public static function getLabel(): string { return 'Test'; }
            public function buildForm(FormBuilderInterface $builder, array $data): void {}
            public function getDefaultData(): array { return $this->defaults; }
        };
    }
}
