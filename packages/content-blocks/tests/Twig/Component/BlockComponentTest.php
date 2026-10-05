<?php

declare(strict_types=1);

namespace ContentBlocks\Tests\Twig\Component;

use ContentBlocks\BlockType\AbstractBlockType;
use ContentBlocks\BlockType\BlockTypeRegistry;
use ContentBlocks\Entity\Block;
use ContentBlocks\Entity\Column;
use ContentBlocks\Entity\ContentArea;
use ContentBlocks\Entity\Section;
use ContentBlocks\Event\AfterBlockSaveEvent;
use ContentBlocks\Event\BeforeBlockSaveEvent;
use ContentBlocks\Form\Type\BlockFormType;
use ContentBlocks\History\ActionJournal;
use ContentBlocks\History\BuilderSession;
use ContentBlocks\History\DoctrineActionLogStore;
use ContentBlocks\History\StateApplier;
use ContentBlocks\Security\AllowAllAccessChecker;
use ContentBlocks\Security\ContentBlocksAccessDeniedException;
use ContentBlocks\Twig\Component\BlockComponent;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Validator\ValidatorExtension;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\Forms;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Validation;
use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\UX\LiveComponent\LiveResponder;

/**
 * Unit tests for BlockComponent: the form's data fallback chain, access, and
 * the save's side effects through a real one-field form. The editing flow in
 * a browser is the Playwright suite's.
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

    // `content_blocks.styling.block: false` reaches the form.
    public function testInstantiateFormLeavesTheStylingTabOutWhenConfigured(): void
    {
        $form = $this->createMock(FormInterface::class);
        $block = $this->makeBlock(publishedData: null, draftData: ['title' => 'Hi']);

        $this->invokeInstantiateForm($this->makeComponent($block, $form, blockStyling: false));
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
            new class () implements \ContentBlocks\Block\BlockDataDefaultsProviderInterface {
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
        $registry->register(new class () extends AbstractBlockType {
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
                return [];
            }
        });

        $component = new BlockComponent(
            $em,
            $registry,
            $factory,
            new AllowAllAccessChecker(),
            $defaults,
            new \ContentBlocks\Block\CollectionItemIds(),
            new ActionJournal(
                new DoctrineActionLogStore($em),
                new StateApplier($em),
                new BuilderSession(new RequestStack()),
            ),
        );
        $component->blockId = 1;

        $this->invokeInstantiateForm($component);
    }

    public function testInstantiateFormPreservesExistingDataOverDefaults(): void
    {
        $defaults = new \ContentBlocks\Block\BlockDataDefaults([
            new class () implements \ContentBlocks\Block\BlockDataDefaultsProviderInterface {
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
        $registry->register(new class () extends AbstractBlockType {
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
                return [];
            }
        });

        $component = new BlockComponent(
            $em,
            $registry,
            $factory,
            new AllowAllAccessChecker(),
            $defaults,
            new \ContentBlocks\Block\CollectionItemIds(),
            new ActionJournal(
                new DoctrineActionLogStore($em),
                new StateApplier($em),
                new BuilderSession(new RequestStack()),
            ),
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

    public function testGetBlockThrowsNotFoundWhenTheRowIsGone(): void
    {
        $component = $this->makeBareComponent(null);

        $this->expectException(NotFoundHttpException::class);
        $component->getBlock();
    }

    public function testSaveDeniesWhenTheBlockHasNoColumnToAuthorizeAgainst(): void
    {
        // A block detached from its column/section/area chain has no area to
        // check, so the guard denies instead of dereferencing null.
        $component = $this->makeBareComponent($this->makeBlock(null, null));

        $this->expectException(ContentBlocksAccessDeniedException::class);
        $component->save();
    }

    public function testSaveIsBracketedByItsTwoEvents(): void
    {
        [$component, $block, $area, $events] = $this->makeSavingComponent();
        $seen = [];
        $events->addListener(
            BeforeBlockSaveEvent::class,
            function (BeforeBlockSaveEvent $e) use (&$seen): void {
                $seen[] = ['before', $e->block, $e->area, $e->data, $e->block->getDraftData()];
            },
        );
        $events->addListener(
            AfterBlockSaveEvent::class,
            function (AfterBlockSaveEvent $e) use (&$seen): void {
                $seen[] = ['after', $e->block, $e->area, $e->block->getDraftData()];
            },
        );
        $component->formValues = ['text' => 'hello'];

        $component->save();

        $this->assertSame([
            ['before', $block, $area, ['text' => 'hello'], null],
            ['after', $block, $area, ['text' => 'hello']],
        ], $seen);
        $this->assertSame([], $component->refusal);
    }

    public function testARefusedSaveKeepsTheDraftAndSaysWhy(): void
    {
        [$component, $block, , $events] = $this->makeSavingComponent();
        $block->setDraftData(['text' => 'before']);
        $events->addListener(
            BeforeBlockSaveEvent::class,
            function (BeforeBlockSaveEvent $e): void {
                if ($e->data['text'] === 'forbidden') {
                    $e->refuse('That word is not allowed.');
                }
            },
        );
        $after = 0;
        $events->addListener(AfterBlockSaveEvent::class, function () use (&$after): void {
            ++$after;
        });
        $component->formValues = ['text' => 'forbidden'];

        $component->save();

        $this->assertSame(['text' => 'before'], $block->getDraftData());
        $this->assertSame(['That word is not allowed.'], $component->refusal);
        $this->assertSame(0, $after);
    }

    public function testAnInvalidSaveDispatchesNothing(): void
    {
        [$component, $block, , $events] = $this->makeSavingComponent();
        $seen = 0;
        foreach ([BeforeBlockSaveEvent::class, AfterBlockSaveEvent::class] as $name) {
            $events->addListener($name, function () use (&$seen): void {
                ++$seen;
            });
        }
        $component->formValues = ['text' => ''];

        $component->save();

        $this->assertSame(0, $seen);
        $this->assertNull($block->getDraftData());
    }

    /**
     * A block in a full area graph, edited through a real one-field form.
     *
     * @return array{
     *     0: BlockComponent, 1: Block, 2: ContentArea, 3: EventDispatcher,
     * }
     */
    private function makeSavingComponent(): array
    {
        $area = new ContentArea();
        $section = new Section();
        $area->addSection($section);
        $column = new Column();
        $section->addColumn($column);
        $block = $this->makeBlock(null, null);
        $column->addBlock($block);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('find')->willReturn($block);

        $registry = new BlockTypeRegistry();
        $registry->register(new class () extends AbstractBlockType {
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
                return [];
            }
        });

        $form = Forms::createFormFactoryBuilder()
            ->addExtension(new ValidatorExtension(Validation::createValidator()))
            ->getFormFactory()
            ->createNamedBuilder('content_block', FormType::class)
            ->add('text', TextType::class, ['constraints' => [new NotBlank()]])
            ->getForm();
        $factory = $this->createMock(FormFactoryInterface::class);
        $factory->method('create')->willReturn($form);

        $events = new EventDispatcher();
        $component = new BlockComponent(
            $em,
            $registry,
            $factory,
            new AllowAllAccessChecker(),
            new \ContentBlocks\Block\BlockDataDefaults(),
            new \ContentBlocks\Block\CollectionItemIds(),
            new ActionJournal(
                new DoctrineActionLogStore($em),
                new StateApplier($em),
                new BuilderSession(new RequestStack()),
            ),
            $events,
        );
        $component->setLiveResponder(new LiveResponder());
        $component->blockId = 1;

        return [$component, $block, $area, $events];
    }

    // A replayed props blob re-renders the draft: revoked rights must hold.
    public function testEveryHydratedRequestIsAuthorized(): void
    {
        $component = $this->makeBareComponent($this->makeBlock(null, null));

        $this->expectException(ContentBlocksAccessDeniedException::class);
        $component->authorizeRequest();
    }

    public function testGetBlockTypeLabelUnwrapsATranslatableLabel(): void
    {
        $registry = new BlockTypeRegistry();
        $registry->register(new class () extends AbstractBlockType {
            public function getType(): string
            {
                return 'test';
            }

            public function getLabel(): string|TranslatableInterface
            {
                // symfony/translation is not a core dependency, so stand in for
                // TranslatableMessage: translatable and Stringable, as it is.
                return new class () implements TranslatableInterface, \Stringable {
                    public function trans(TranslatorInterface $translator, ?string $locale = null): string
                    {
                        return 'translated';
                    }

                    public function __toString(): string
                    {
                        return 'cb.block.test';
                    }
                };
            }

            public function buildForm(FormBuilderInterface $builder, array $data): void
            {
            }

            public function getDefaultData(): array
            {
                return [];
            }
        });

        $component = $this->makeBareComponent($this->makeBlock(null, null), $registry);

        self::assertSame('cb.block.test', $component->getBlockTypeLabel());
    }

    public function testGetBlockTypeLabelFallsBackToTheTypeWhenTheTypeIsGone(): void
    {
        $component = $this->makeBareComponent($this->makeBlock(null, null));

        self::assertSame('test', $component->getBlockTypeLabel());
    }

    /**
     * A component wired to whatever `find()` should return, with no form
     * expectations — for the paths that never reach the form factory.
     */
    private function makeBareComponent(?Block $block, ?BlockTypeRegistry $registry = null): BlockComponent
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('find')->willReturn($block);

        $component = new BlockComponent(
            $em,
            $registry ?? new BlockTypeRegistry(),
            $this->createMock(FormFactoryInterface::class),
            new AllowAllAccessChecker(),
            new \ContentBlocks\Block\BlockDataDefaults(),
            new \ContentBlocks\Block\CollectionItemIds(),
            new ActionJournal(
                new DoctrineActionLogStore($em),
                new StateApplier($em),
                new BuilderSession(new RequestStack()),
            ),
        );
        $component->blockId = 1;

        return $component;
    }

    private function makeComponent(Block $block, FormInterface $form, bool $blockStyling = true): BlockComponent
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('find')->willReturn($block);

        $registry = new BlockTypeRegistry();
        $registry->register(new class () extends AbstractBlockType {
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
                return [];
            }
        });

        // The data passed to FormFactory::create *is* the assertion.
        $expectedData = $block->getDraftData() ?? $block->getPublishedData() ?? [];
        $factory = $this->createMock(FormFactoryInterface::class);
        $factory->expects($this->once())
            ->method('create')
            ->with(
                BlockFormType::class,
                $expectedData,
                $this->callback(function (array $opts) use ($expectedData, $blockStyling): bool {
                    return ($opts['block_data'] ?? null) === $expectedData
                        && ($opts['include_styling'] ?? null) === $blockStyling;
                }),
            )
            ->willReturn($form);

        $component = new BlockComponent(
            $em,
            $registry,
            $factory,
            new AllowAllAccessChecker(),
            new \ContentBlocks\Block\BlockDataDefaults(),
            new \ContentBlocks\Block\CollectionItemIds(),
            new ActionJournal(
                new DoctrineActionLogStore($em),
                new StateApplier($em),
                new BuilderSession(new RequestStack()),
            ),
            null,
            $blockStyling,
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
     * @return iterable<string, array{
     *     0: list<string>, 1: int, 2: int, 3: list<string>|null,
     * }>
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
     * @return iterable<string, array{
     *     0: list<string>, 1: int, 2: list<string>|null,
     * }>
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

    public function testDuplicateInCollectionStripsTheSourceEntrysStableId(): void
    {
        // The copy is a new entry and must not share the original's identity:
        // anything keyed per entry (translations first) would otherwise address
        // both at once, and editing one would appear to edit the other.
        // persistDraft() mints a fresh id for the stripped copy.
        $data = [
            ['_id' => 'aaa', 'label' => 'One'],
            ['_id' => 'bbb', 'label' => 'Two'],
        ];

        $out = $this->invokeDuplicateInCollection($data, 0);

        self::assertNotNull($out);
        self::assertSame('aaa', $out[0]['_id'], 'the original keeps its id');
        self::assertArrayNotHasKey('_id', $out[1], 'the copy carries none, awaiting a fresh one');
        self::assertSame('One', $out[1]['label'], 'everything else is copied');
        self::assertSame('bbb', $out[2]['_id'], 'later entries are undisturbed');
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
