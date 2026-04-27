<?php

declare(strict_types=1);

namespace ContentBlocks\Tests;

use ContentBlocks\BlockType\BlockTypeInterface;
use ContentBlocks\BlockType\BlockTypeRegistry;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Form\FormBuilderInterface;

final class BlockTypeRegistryTest extends TestCase
{
    public function testRegisterAndRetrieve(): void
    {
        $registry = new BlockTypeRegistry();
        $block = new class extends \ContentBlocks\BlockType\AbstractBlockType {
            public static function getType(): string { return 'test'; }
            public static function getLabel(): string { return 'Test'; }
            public function buildForm(FormBuilderInterface $builder, array $data): void {}
            public function getDefaultData(): array { return ['foo' => 'bar']; }
        };

        $registry->register($block);

        $this->assertTrue($registry->has('test'));
        $this->assertSame($block, $registry->get('test'));
        $this->assertArrayHasKey('test', $registry->all());
        $this->assertSame(['test' => 'Test'], $registry->getChoices());
    }

    public function testGetUnknownTypeThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new BlockTypeRegistry())->get('unknown');
    }
}
