<?php

declare(strict_types=1);

namespace ContentBlocks\Tests\Form\Type;

use ContentBlocks\BlockType\AbstractBlockType;
use ContentBlocks\BlockType\BlockTypeInterface;
use ContentBlocks\Form\Extension\BlockFormExtensionCollection;
use ContentBlocks\Form\Extension\BlockFormExtensionInterface;
use ContentBlocks\Form\Type\BlockFormType;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\Forms;

final class BlockFormTypeTest extends TestCase
{
    public function testBuildsTheBlockOwnFieldsAndStylingTab(): void
    {
        $form = $this->createBlockForm(new BlockFormExtensionCollection(), $this->buttonBlock(), ['url' => '']);

        $this->assertTrue($form->has('url'), 'block field');
        $this->assertTrue($form->has('styling'), 'styling tab always added');
    }

    public function testTargetedExtensionAddsAFieldToItsBlock(): void
    {
        $extensions = new BlockFormExtensionCollection([[$this->relExtension(), ['button']]]);

        $form = $this->createBlockForm($extensions, $this->buttonBlock(), ['url' => '', 'rel' => 'nofollow']);

        $this->assertTrue($form->has('rel'), 'extension field present on targeted block');
    }

    public function testTargetedExtensionIsSkippedForOtherBlocks(): void
    {
        $extensions = new BlockFormExtensionCollection([[$this->relExtension(), ['button']]]);

        $form = $this->createBlockForm($extensions, $this->textBlock(), ['content' => '']);

        $this->assertFalse($form->has('rel'), 'extension field absent on non-targeted block');
    }

    public function testExtensionFieldRoundTripsIntoBlockData(): void
    {
        $extensions = new BlockFormExtensionCollection([[$this->relExtension(), ['button']]]);

        $form = $this->createBlockForm($extensions, $this->buttonBlock(), ['url' => '', 'rel' => '']);
        $form->submit([
            'url' => 'https://example.test',
            'rel' => 'noopener',
            'styling' => [],
        ]);

        $this->assertTrue($form->isSynchronized());
        $data = $form->getData();
        $this->assertSame('https://example.test', $data['url']);
        $this->assertSame('noopener', $data['rel'], 'extension field persists into Block.data');
    }

    public function testGlobalExtensionReceivesTheBlockTypeId(): void
    {
        $global = new class implements BlockFormExtensionInterface {
            /** @var list<string> */
            public array $seen = [];

            public function buildForm(FormBuilderInterface $builder, array $data, string $blockType): void
            {
                $this->seen[] = $blockType;
            }
        };

        $this->createBlockForm(new BlockFormExtensionCollection([[$global, ['*']]]), $this->buttonBlock(), ['url' => '']);

        $this->assertSame(['button'], $global->seen);
    }

    public function testExtensionCanRemoveABlockField(): void
    {
        // The builder handed to an extension is the block's own form builder,
        // so `remove()` is part of the seam's contract: a host can drop a field
        // its design system doesn't allow.
        $remover = new class implements BlockFormExtensionInterface {
            public function buildForm(FormBuilderInterface $builder, array $data, string $blockType): void
            {
                $builder->remove('fullWidth');
            }
        };

        $form = $this->createBlockForm(
            new BlockFormExtensionCollection([[$remover, ['button']]]),
            $this->multiFieldBlock(),
            ['text' => '', 'url' => '', 'fullWidth' => true],
        );

        $this->assertFalse($form->has('fullWidth'));
        $this->assertTrue($form->has('text'), 'the other fields are untouched');
    }

    public function testRemovedFieldKeepsItsStoredValueAndIgnoresPostedOnes(): void
    {
        $remover = new class implements BlockFormExtensionInterface {
            public function buildForm(FormBuilderInterface $builder, array $data, string $blockType): void
            {
                $builder->remove('fullWidth');
            }
        };

        $form = $this->createBlockForm(
            new BlockFormExtensionCollection([[$remover, ['button']]]),
            $this->multiFieldBlock(),
            ['text' => 'Go', 'url' => '', 'fullWidth' => true],
        );
        // A crafted POST still carrying the removed field:
        $form->submit(['text' => 'Go', 'url' => 'https://example.test', 'fullWidth' => '0', 'styling' => []]);

        $this->assertTrue($form->isSynchronized());
        $data = $form->getData();

        // The form's model data *is* the block's data array (see
        // BlockComponent::instantiateForm), so removing a field freezes its
        // stored value rather than deleting it: no data loss on save…
        $this->assertTrue($data['fullWidth'], 'stored value of a removed field survives the save');
        // …and the POSTed value is ignored, because only declared children map.
        $this->assertSame(['fullWidth' => '0'], $form->getExtraData());
        $this->assertSame('https://example.test', $data['url']);
    }

    public function testExtensionCanReorderBlockFields(): void
    {
        // Reordering = re-adding the existing child builders in the wanted
        // order (children render in insertion order). Capturing the child
        // builder keeps its type and options.
        $reorder = new class implements BlockFormExtensionInterface {
            public function buildForm(FormBuilderInterface $builder, array $data, string $blockType): void
            {
                foreach (['url', 'text', 'fullWidth'] as $name) {
                    $child = $builder->get($name);
                    $builder->remove($name);
                    $builder->add($child);
                }
            }
        };

        $form = $this->createBlockForm(
            new BlockFormExtensionCollection([[$reorder, ['button']]]),
            $this->multiFieldBlock(),
            ['text' => '', 'url' => '', 'fullWidth' => false],
        );

        $names = array_keys(iterator_to_array($form));
        // The styling tab is added by BlockFormType *after* the extensions run,
        // so it always stays last whatever an extension does.
        $this->assertSame(['url', 'text', 'fullWidth', 'styling'], $names);
    }

    public function testReorderKeepsTheFieldTypeAndOptions(): void
    {
        $reorder = new class implements BlockFormExtensionInterface {
            public function buildForm(FormBuilderInterface $builder, array $data, string $blockType): void
            {
                $child = $builder->get('fullWidth');
                $builder->remove('fullWidth');
                $builder->add($child);
            }
        };

        $form = $this->createBlockForm(
            new BlockFormExtensionCollection([[$reorder, ['button']]]),
            $this->multiFieldBlock(),
            ['text' => '', 'url' => '', 'fullWidth' => true],
        );

        // Re-added by builder instance, not by name: the CheckboxType and its
        // `data`/`required` options survive the move.
        $this->assertInstanceOf(CheckboxType::class, $form->get('fullWidth')->getConfig()->getType()->getInnerType());
        $this->assertFalse($form->get('fullWidth')->getConfig()->getRequired());
        $this->assertTrue($form->get('fullWidth')->getData());
    }

    /**
     * @param array<string, mixed> $data
     */
    private function createBlockForm(
        BlockFormExtensionCollection $extensions,
        BlockTypeInterface $blockType,
        array $data,
    ): FormInterface {
        // A bare factory instantiates child types (StylingType, PaletteColorType,
        // TextType) via their no-arg / nullable constructors — same resolution
        // TypeTestCase uses — while letting us inject a per-test collection.
        $factory = Forms::createFormFactoryBuilder()
            ->addType(new BlockFormType($extensions))
            ->getFormFactory();

        return $factory->create(BlockFormType::class, $data, [
            'block_type' => $blockType,
            'block_data' => $data,
        ]);
    }

    private function relExtension(): BlockFormExtensionInterface
    {
        return new class implements BlockFormExtensionInterface {
            public function buildForm(FormBuilderInterface $builder, array $data, string $blockType): void
            {
                $builder->add('rel', TextType::class, [
                    'required' => false,
                    'data' => $data['rel'] ?? '',
                ]);
            }
        };
    }

    private function buttonBlock(): BlockTypeInterface
    {
        return new class extends AbstractBlockType {
            public static function getType(): string
            {
                return 'button';
            }

            public static function getLabel(): string
            {
                return 'Button';
            }

            public function buildForm(FormBuilderInterface $builder, array $data): void
            {
                $builder->add('url', TextType::class, ['required' => false, 'data' => $data['url'] ?? '']);
            }

            public function getDefaultData(): array
            {
                return ['url' => ''];
            }
        };
    }

    /** A `button` block with several fields, for the remove / reorder cases. */
    private function multiFieldBlock(): BlockTypeInterface
    {
        return new class extends AbstractBlockType {
            public static function getType(): string
            {
                return 'button';
            }

            public static function getLabel(): string
            {
                return 'Button';
            }

            public function buildForm(FormBuilderInterface $builder, array $data): void
            {
                $builder
                    ->add('text', TextType::class, ['required' => false, 'data' => $data['text'] ?? ''])
                    ->add('url', TextType::class, ['required' => false, 'data' => $data['url'] ?? ''])
                    ->add('fullWidth', CheckboxType::class, ['required' => false, 'data' => $data['fullWidth'] ?? false]);
            }

            public function getDefaultData(): array
            {
                return ['text' => '', 'url' => '', 'fullWidth' => false];
            }
        };
    }

    private function textBlock(): BlockTypeInterface
    {
        return new class extends AbstractBlockType {
            public static function getType(): string
            {
                return 'text';
            }

            public static function getLabel(): string
            {
                return 'Text';
            }

            public function buildForm(FormBuilderInterface $builder, array $data): void
            {
                $builder->add('content', TextType::class, ['required' => false, 'data' => $data['content'] ?? '']);
            }

            public function getDefaultData(): array
            {
                return ['content' => ''];
            }
        };
    }
}
