<?php

declare(strict_types=1);

namespace ContentBlocks\Tests\Form\Extension;

use ContentBlocks\Form\Extension\BlockFormExtensionCollection;
use ContentBlocks\Form\Extension\BlockFormExtensionInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Form\FormBuilderInterface;

final class BlockFormExtensionCollectionTest extends TestCase
{
    public function testGlobalExtensionRunsForEveryBlock(): void
    {
        $global = $this->recordingExtension();
        $collection = new BlockFormExtensionCollection([[$global, ['*']]]);

        $collection->applyTo($this->builder(), [], 'button');
        $collection->applyTo($this->builder(), [], 'card');

        $this->assertSame(['button', 'card'], $global->seen);
    }

    public function testTargetedExtensionRunsOnlyForItsType(): void
    {
        $buttonOnly = $this->recordingExtension();
        $collection = new BlockFormExtensionCollection([[$buttonOnly, ['button']]]);

        $collection->applyTo($this->builder(), [], 'button');
        $collection->applyTo($this->builder(), [], 'card');

        $this->assertSame(['button'], $buttonOnly->seen);
    }

    public function testMultiTargetExtensionRunsForAnyListedType(): void
    {
        $ext = $this->recordingExtension();
        $collection = new BlockFormExtensionCollection([[$ext, ['button', 'card']]]);

        $collection->applyTo($this->builder(), [], 'button');
        $collection->applyTo($this->builder(), [], 'card');
        $collection->applyTo($this->builder(), [], 'text');

        $this->assertSame(['button', 'card'], $ext->seen);
    }

    public function testExtensionsRunInGivenOrder(): void
    {
        $order = [];
        $first = $this->recordingExtension(static function () use (&$order): void {
            $order[] = 'first';
        });
        $second = $this->recordingExtension(static function () use (&$order): void {
            $order[] = 'second';
        });

        // The collection preserves the order it is constructed with — the
        // compiler pass is what sorts by priority before handing pairs over.
        $collection = new BlockFormExtensionCollection([[$first, ['*']], [$second, ['*']]]);
        $collection->applyTo($this->builder(), [], 'button');

        $this->assertSame(['first', 'second'], $order);
    }

    public function testDataAndTypeArePassedThrough(): void
    {
        $ext = $this->recordingExtension();
        $collection = new BlockFormExtensionCollection([[$ext, ['*']]]);

        $collection->applyTo($this->builder(), ['rel' => 'nofollow'], 'button');

        $this->assertSame([['rel' => 'nofollow'], 'button'], $ext->lastCall);
    }

    public function testEmptyCollectionIsANoop(): void
    {
        $collection = new BlockFormExtensionCollection();

        // Nothing to assert beyond "does not throw".
        $collection->applyTo($this->builder(), [], 'button');
        $this->addToAssertionCount(1);
    }

    public function testAcceptsATraversableOfPairs(): void
    {
        $ext = $this->recordingExtension();
        $collection = new BlockFormExtensionCollection((static function () use ($ext) {
            yield [$ext, ['button']];
        })());

        $collection->applyTo($this->builder(), [], 'button');

        $this->assertSame(['button'], $ext->seen);
    }

    private function builder(): FormBuilderInterface
    {
        return $this->createStub(FormBuilderInterface::class);
    }

    private function recordingExtension(?\Closure $onBuild = null): BlockFormExtensionInterface
    {
        return new class($onBuild) implements BlockFormExtensionInterface {
            /** @var list<string> */
            public array $seen = [];
            /** @var array{0: array<string,mixed>, 1: string}|null */
            public ?array $lastCall = null;

            public function __construct(private readonly ?\Closure $onBuild)
            {
            }

            public function buildForm(FormBuilderInterface $builder, array $data, string $blockType): void
            {
                $this->seen[] = $blockType;
                $this->lastCall = [$data, $blockType];
                if (null !== $this->onBuild) {
                    ($this->onBuild)();
                }
            }
        };
    }
}
