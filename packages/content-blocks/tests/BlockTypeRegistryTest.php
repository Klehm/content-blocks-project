<?php

declare(strict_types=1);

namespace ContentBlocks\Tests;

use ContentBlocks\BlockType\AbstractBlockType;
use ContentBlocks\BlockType\BlockTypeRegistry;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Form\FormBuilderInterface;

final class BlockTypeRegistryTest extends TestCase
{
    public function testRegisterAndRetrieve(): void
    {
        $registry = new BlockTypeRegistry();
        $block = new class () extends AbstractBlockType {
            public function getType(): string
            {
                return 'test';
            }
            public function getLabel(): string
            {
                return 'Test';
            }
            public function buildForm(FormBuilderInterface $builder, array $data): void
            {
            }
            public function getDefaultData(): array
            {
                return ['foo' => 'bar'];
            }
        };

        $registry->register($block);

        $this->assertTrue($registry->has('test'));
        $this->assertSame($block, $registry->get('test'));
        $this->assertArrayHasKey('test', $registry->all());
        $this->assertSame(['test' => 'Test'], $registry->getChoices());
    }

    /**
     * Type and label are instance methods: one class, configured twice,
     * serves two types.
     */
    public function testOneClassCanServeTwoTypes(): void
    {
        $registry = new BlockTypeRegistry();
        $registry->register(new ConfiguredBlock('banner', 'Banner'));
        $registry->register(new ConfiguredBlock('insert', 'Insert'));

        $this->assertSame(
            ['banner' => 'Banner', 'insert' => 'Insert'],
            $registry->getChoices(),
        );
        $this->assertSame('insert', $registry->get('insert')->getType());
    }

    public function testGetUnknownTypeThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new BlockTypeRegistry())->get('unknown');
    }
}

final class ConfiguredBlock extends AbstractBlockType
{
    public function __construct(
        private readonly string $type,
        private readonly string $label,
    ) {
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function buildForm(FormBuilderInterface $builder, array $data): void
    {
    }

    public function getDefaultData(): array
    {
        return [];
    }
}
