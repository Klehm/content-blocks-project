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
}
