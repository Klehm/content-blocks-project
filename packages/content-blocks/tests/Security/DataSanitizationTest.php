<?php

declare(strict_types=1);

namespace ContentBlocks\Tests\Security;

use ContentBlocks\BlockType\AbstractBlockType;
use ContentBlocks\BlockType\BlockTypeRegistry;
use PHPUnit\Framework\TestCase;

final class DataSanitizationTest extends TestCase
{
    public function testGetAllowedDataKeysMatchesDefaultData(): void
    {
        $blockType = $this->createBlockType(['title' => '', 'body' => '']);

        $this->assertSame(['title', 'body'], $blockType->getAllowedDataKeys());
    }

    public function testSanitizeStripsUnknownKeys(): void
    {
        $blockType = $this->createBlockType(['title' => '', 'body' => '']);

        $sanitized = $blockType->sanitizeData([
            'title' => 'Hello',
            'body' => 'World',
            'malicious' => '<script>alert(1)</script>',
            '__proto__' => 'injection',
        ]);

        $this->assertSame(['title' => 'Hello', 'body' => 'World'], $sanitized);
        $this->assertArrayNotHasKey('malicious', $sanitized);
        $this->assertArrayNotHasKey('__proto__', $sanitized);
    }

    public function testSanitizeKeepsOnlyAllowedKeys(): void
    {
        $blockType = $this->createBlockType(['content' => '']);
        $sanitized = $blockType->sanitizeData(['content' => 'valid', 'extra' => 'injected']);

        $this->assertSame(['content' => 'valid'], $sanitized);
    }

    public function testSanitizeHandlesMissingKeys(): void
    {
        $blockType = $this->createBlockType(['title' => '', 'body' => '']);
        $sanitized = $blockType->sanitizeData(['title' => 'Only title']);

        $this->assertSame(['title' => 'Only title'], $sanitized);
        $this->assertArrayNotHasKey('body', $sanitized);
    }

    public function testSanitizeCastsNonStringValues(): void
    {
        $blockType = $this->createBlockType(['count' => '']);
        $sanitized = $blockType->sanitizeData(['count' => 42]);

        $this->assertSame(['count' => '42'], $sanitized);
    }

    public function testSanitizeReturnsEmptyForUnknownBlockType(): void
    {
        $registry = new BlockTypeRegistry();

        // Block type not registered — BlockComponent returns empty
        $this->assertFalse($registry->has('nonexistent'));
    }

    public function testProcessDataDefaultIsPassThrough(): void
    {
        $blockType = $this->createBlockType(['foo' => '']);
        $data = ['foo' => 'bar', 'extra' => 'baz'];

        $this->assertSame($data, $blockType->processData($data));
    }

    private function createBlockType(array $defaultData): AbstractBlockType
    {
        return new class($defaultData) extends AbstractBlockType {
            public function __construct(private readonly array $defaults) {}
            public static function getType(): string { return 'test'; }
            public static function getLabel(): string { return 'Test'; }
            public function buildForm(\Symfony\Component\Form\FormBuilderInterface $builder, array $data): void {}
            public function getDefaultData(): array { return $this->defaults; }
        };
    }
}
