<?php

declare(strict_types=1);

namespace ContentBlocks\Tests\Twig\Component;

use ContentBlocks\BlockType\AbstractBlockType;
use ContentBlocks\BlockType\BlockTypeRegistry;
use ContentBlocks\Entity\Block;
use ContentBlocks\Form\Type\BlockFormType;
use ContentBlocks\Security\AllowAllAccessChecker;
use ContentBlocks\Twig\Component\BlockComponent;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;

/**
 * Unit tests for BlockComponent::instantiateForm() — the data fallback chain.
 *
 * Full save/cancel flow tests live in the integration suite (phase 2 onward),
 * since they exercise the LiveCollectionTrait's form lifecycle which is too
 * tightly coupled to the Live Component framework to mock cleanly here.
 */
final class BlockComponentTest extends TestCase
{
    public function testInstantiateFormPrefersDraftDataOverPublishedData(): void
    {
        $form = $this->makeFormWithExpectedData(['title' => 'New']);

        $block = $this->makeBlock(
            publishedData: ['title' => 'Old'],
            draftData: ['title' => 'New'],
        );
        $component = $this->makeComponent($block, $form);

        $this->invokeInstantiateForm($component);
    }

    public function testInstantiateFormFallsBackToPublishedDataWhenNoDraft(): void
    {
        $form = $this->makeFormWithExpectedData(['title' => 'Hello']);

        $block = $this->makeBlock(
            publishedData: ['title' => 'Hello'],
            draftData: null,
        );
        $component = $this->makeComponent($block, $form);

        $this->invokeInstantiateForm($component);
    }

    public function testInstantiateFormUsesEmptyArrayWhenNeitherDataIsSet(): void
    {
        $form = $this->makeFormWithExpectedData([]);

        $block = $this->makeBlock(
            publishedData: null,
            draftData: null,
        );
        $component = $this->makeComponent($block, $form);

        $this->invokeInstantiateForm($component);
    }

    public function testInstantiateFormBackfillsBlockDataDefaultsIntoInitialFormData(): void
    {
        $defaults = new \ContentBlocks\Block\BlockDataDefaults([
            new class implements \ContentBlocks\Block\BlockDataDefaultsProviderInterface {
                public function getDefaults(): array
                {
                    return ['styling' => ['backgroundColor' => '#ffffff']];
                }
            },
        ]);

        // Block has its own title; defaults add styling.backgroundColor
        // on top via a recursive merge — both must reach the form.
        // Key order reflects array_replace_recursive: defaults first,
        // then values from the block data overwrite/append.
        $expected = [
            'styling' => ['backgroundColor' => '#ffffff'],
            'title' => 'Hello',
        ];
        $form = $this->createMock(FormInterface::class);
        $factory = $this->createMock(FormFactoryInterface::class);
        $factory->expects($this->once())
            ->method('create')
            ->with(
                BlockFormType::class,
                $expected,
                $this->callback(fn (array $opts): bool => ($opts['block_data'] ?? null) === $expected),
            )
            ->willReturn($form);

        $block = $this->makeBlock(publishedData: ['title' => 'Hello'], draftData: null);
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('find')->willReturn($block);

        $registry = new BlockTypeRegistry();
        $registry->register(new class extends AbstractBlockType {
            public static function getType(): string { return 'test'; }
            public static function getLabel(): string { return 'Test'; }
            public function buildForm(FormBuilderInterface $builder, array $data): void {}
            public function getDefaultData(): array { return []; }
        });

        $component = new BlockComponent(
            $em,
            $registry,
            $factory,
            new AllowAllAccessChecker(),
            $defaults,
        );
        $component->blockId = 1;

        $this->invokeInstantiateForm($component);
    }

    public function testInstantiateFormPreservesExistingDataOverDefaults(): void
    {
        $defaults = new \ContentBlocks\Block\BlockDataDefaults([
            new class implements \ContentBlocks\Block\BlockDataDefaultsProviderInterface {
                public function getDefaults(): array
                {
                    return ['styling' => ['backgroundColor' => '#ffffff']];
                }
            },
        ]);

        // Stored bg diverges from default; the merge must keep the
        // user's value (recursive replace, not replace-from-defaults).
        $expected = ['styling' => ['backgroundColor' => '#ff0000']];
        $form = $this->createMock(FormInterface::class);
        $factory = $this->createMock(FormFactoryInterface::class);
        $factory->expects($this->once())
            ->method('create')
            ->with(BlockFormType::class, $expected, $this->anything())
            ->willReturn($form);

        $block = $this->makeBlock(
            publishedData: null,
            draftData: ['styling' => ['backgroundColor' => '#ff0000']],
        );
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('find')->willReturn($block);

        $registry = new BlockTypeRegistry();
        $registry->register(new class extends AbstractBlockType {
            public static function getType(): string { return 'test'; }
            public static function getLabel(): string { return 'Test'; }
            public function buildForm(FormBuilderInterface $builder, array $data): void {}
            public function getDefaultData(): array { return []; }
        });

        $component = new BlockComponent(
            $em,
            $registry,
            $factory,
            new AllowAllAccessChecker(),
            $defaults,
        );
        $component->blockId = 1;

        $this->invokeInstantiateForm($component);
    }

    /**
     * @param array<string, mixed>|null $publishedData
     * @param array<string, mixed>|null $draftData
     */
    private function makeBlock(?array $publishedData, ?array $draftData): Block
    {
        $block = new Block();
        $block->setType('test');
        $block->setPublishedData($publishedData);
        $block->setDraftData($draftData);

        return $block;
    }

    /**
     * Returns a FormFactory mock that asserts `create()` was called with the
     * expected initial data, and returns the supplied form.
     *
     * @param array<string, mixed> $expectedData
     */
    private function makeFormWithExpectedData(array $expectedData): FormInterface
    {
        $form = $this->createMock(FormInterface::class);

        return $form;
    }

    private function makeComponent(Block $block, FormInterface $form): BlockComponent
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('find')->willReturn($block);

        $registry = new BlockTypeRegistry();
        $registry->register(new class extends AbstractBlockType {
            public static function getType(): string { return 'test'; }
            public static function getLabel(): string { return 'Test'; }
            public function buildForm(FormBuilderInterface $builder, array $data): void {}
            public function getDefaultData(): array { return []; }
        });

        // Capture the data passed to FormFactory::create — that's the assertion.
        $expectedData = $block->getDraftData() ?? $block->getPublishedData() ?? [];
        $factory = $this->createMock(FormFactoryInterface::class);
        $factory->expects($this->once())
            ->method('create')
            ->with(
                BlockFormType::class,
                $expectedData,
                $this->callback(function (array $opts) use ($expectedData): bool {
                    return ($opts['block_data'] ?? null) === $expectedData;
                }),
            )
            ->willReturn($form);

        $component = new BlockComponent(
            $em,
            $registry,
            $factory,
            new AllowAllAccessChecker(),
            new \ContentBlocks\Block\BlockDataDefaults(),
        );
        $component->blockId = 1;

        return $component;
    }

    private function invokeInstantiateForm(BlockComponent $component): FormInterface
    {
        $method = (new \ReflectionClass($component))->getMethod('instantiateForm');

        return $method->invoke($component);
    }

    /**
     * @param list<string>      $data
     * @param list<string>|null $expected
     */
    #[DataProvider('reorderCollectionProvider')]
    public function testReorderCollectionMovesItemPositionally(array $data, int $from, int $to, ?array $expected): void
    {
        self::assertSame($expected, $this->invokeReorderCollection($data, $from, $to));
    }

    /**
     * @return iterable<string, array{0: list<string>, 1: int, 2: int, 3: list<string>|null}>
     */
    public static function reorderCollectionProvider(): iterable
    {
        yield 'move down one slot' => [['a', 'b', 'c'], 0, 1, ['b', 'a', 'c']];
        yield 'move up one slot' => [['a', 'b', 'c'], 2, 1, ['a', 'c', 'b']];
        yield 'drag last to first' => [['a', 'b', 'c', 'd'], 3, 0, ['d', 'a', 'b', 'c']];
        yield 'drag first to last' => [['a', 'b', 'c'], 0, 2, ['b', 'c', 'a']];
        yield 'same index is a no-op' => [['a', 'b'], 1, 1, null];
        yield 'from out of range' => [['a', 'b'], 5, 0, null];
        yield 'to out of range' => [['a', 'b'], 0, 5, null];
        yield 'negative index' => [['a', 'b'], -1, 0, null];
    }

    public function testReorderCollectionNormalizesSparseKeysFromPriorDeletion(): void
    {
        // A LiveCollection delete leaves a hole in the keys (here index 1 is
        // gone). SortableJS still reports contiguous DOM positions, so the
        // reorder must operate on the positional view and return a 0..n list.
        $sparse = [0 => 'a', 2 => 'c', 3 => 'd'];

        // Positionally: [a, c, d]; move position 0 (a) to position 2.
        self::assertSame(['c', 'd', 'a'], $this->invokeReorderCollection($sparse, 0, 2));
    }

    /**
     * @param array<int|string, mixed> $data
     *
     * @return list<mixed>|null
     */
    private function invokeReorderCollection(array $data, int $from, int $to): ?array
    {
        $method = (new \ReflectionClass(BlockComponent::class))->getMethod('reorderCollection');
        $method->setAccessible(true);

        return $method->invoke(null, $data, $from, $to);
    }

    /**
     * @param list<string>      $data
     * @param list<string>|null $expected
     */
    #[DataProvider('duplicateInCollectionProvider')]
    public function testDuplicateInCollectionInsertsCopyAfterItem(array $data, int $index, ?array $expected): void
    {
        self::assertSame($expected, $this->invokeDuplicateInCollection($data, $index));
    }

    /**
     * @return iterable<string, array{0: list<string>, 1: int, 2: list<string>|null}>
     */
    public static function duplicateInCollectionProvider(): iterable
    {
        yield 'duplicate first' => [['a', 'b', 'c'], 0, ['a', 'a', 'b', 'c']];
        yield 'duplicate middle' => [['a', 'b', 'c'], 1, ['a', 'b', 'b', 'c']];
        yield 'duplicate last appends' => [['a', 'b', 'c'], 2, ['a', 'b', 'c', 'c']];
        yield 'single item' => [['a'], 0, ['a', 'a']];
        yield 'index out of range' => [['a', 'b'], 5, null];
        yield 'negative index' => [['a', 'b'], -1, null];
    }

    public function testDuplicateInCollectionNormalizesSparseKeysFromPriorDeletion(): void
    {
        // A prior LiveCollection delete leaves a hole in the keys. The copy
        // must be inserted by positional index and the result returned as a
        // contiguous 0..n list (the collection re-renders positionally).
        $sparse = [0 => 'a', 2 => 'c', 3 => 'd'];

        // Positionally: [a, c, d]; duplicate position 1 (c) → [a, c, c, d].
        self::assertSame(['a', 'c', 'c', 'd'], $this->invokeDuplicateInCollection($sparse, 1));
    }

    /**
     * @param array<int|string, mixed> $data
     *
     * @return list<mixed>|null
     */
    private function invokeDuplicateInCollection(array $data, int $index): ?array
    {
        $method = (new \ReflectionClass(BlockComponent::class))->getMethod('duplicateInCollection');
        $method->setAccessible(true);

        return $method->invoke(null, $data, $index);
    }
}
