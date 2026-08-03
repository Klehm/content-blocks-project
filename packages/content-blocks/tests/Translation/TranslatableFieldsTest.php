<?php

declare(strict_types=1);

namespace ContentBlocks\Tests\Translation;

use ContentBlocks\BlockType\AbstractBlockType;
use ContentBlocks\BlockType\BlockTypeRegistry;
use ContentBlocks\Form\Extension\BlockFormExtensionCollection;
use ContentBlocks\Form\Extension\BlockFormExtensionInterface;
use ContentBlocks\Form\Extension\TranslatableFieldTypeExtension;
use ContentBlocks\Form\Type\BlockFormType;
use ContentBlocks\Translation\TranslatableFields;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\Forms;

/**
 * The convention ships at 1.0 without a consumer, so these tests are what keeps
 * it honest: they pin what a satellite translation package will read back.
 */
final class TranslatableFieldsTest extends TestCase
{
    private function fields(?BlockFormExtensionCollection $extensions = null, bool $registered = true): TranslatableFields
    {
        $registry = new BlockTypeRegistry();
        if ($registered) {
            $registry->register(new TranslatableFixtureBlock());
        }

        $factory = Forms::createFormFactoryBuilder()
            ->addType(new BlockFormType($extensions ?? new BlockFormExtensionCollection()))
            ->addTypeExtension(new TranslatableFieldTypeExtension())
            ->getFormFactory();

        return new TranslatableFields($registry, $factory);
    }

    public function testOnlyTaggedFieldsAreReported(): void
    {
        $fields = $this->fields()->forBlockType('fixture');

        // `heading` and `body` are tagged; `align` (an enum) is not, and neither
        // is the `styling` sub-form BlockFormType appends to every block.
        $this->assertContains('heading', $fields);
        $this->assertContains('body', $fields);
        $this->assertNotContains('align', $fields);
        $this->assertSame([], array_filter($fields, fn (string $f) => str_starts_with($f, 'styling')));
    }

    public function testCollectionEntriesAreReportedAsRepeatingPaths(): void
    {
        // A collection has no children until it is bound to data, so the walk
        // has to descend into a prototype of its entry_type.
        $this->assertContains('items[].label', $this->fields()->forBlockType('fixture'));
    }

    public function testFieldsKeepFormDeclarationOrder(): void
    {
        $this->assertSame(
            ['heading', 'body', 'items[].label'],
            $this->fields()->forBlockType('fixture'),
        );
    }

    public function testAFieldAddedByAHostExtensionIsPickedUp(): void
    {
        // Same rule BlockDataKeys follows: read the built form, not a static
        // declaration, so a host adding a translatable field to someone else's
        // block gets it for free.
        $extension = new class implements BlockFormExtensionInterface {
            public function buildForm(FormBuilderInterface $builder, array $data, string $blockType): void
            {
                $builder->add('subtitle', TextType::class, [
                    'required' => false,
                    'cb_translatable' => true,
                ]);
            }
        };

        $fields = $this->fields(new BlockFormExtensionCollection([[$extension, ['*']]]))
            ->forBlockType('fixture');

        $this->assertContains('subtitle', $fields);
    }

    public function testAnUnregisteredTypeReportsNothing(): void
    {
        $this->assertSame([], $this->fields(registered: false)->forBlockType('fixture'));
    }
}

final class TranslatableFixtureBlock extends AbstractBlockType
{
    public static function getType(): string
    {
        return 'fixture';
    }

    public static function getLabel(): string
    {
        return 'Fixture';
    }

    public function buildForm(FormBuilderInterface $builder, array $data): void
    {
        $builder
            ->add('heading', TextType::class, ['required' => false, 'cb_translatable' => true])
            ->add('body', TextType::class, ['required' => false, 'cb_translatable' => true])
            // Same in every language — deliberately untagged.
            ->add('align', ChoiceType::class, [
                'required' => false,
                'choices' => ['start' => 'start', 'center' => 'center', 'end' => 'end'],
            ])
            ->add('items', CollectionType::class, [
                'entry_type' => TranslatableFixtureItemType::class,
                'allow_add' => true,
            ]);
    }

    public function getDefaultData(): array
    {
        return ['heading' => '', 'body' => '', 'align' => 'start', 'items' => []];
    }
}

final class TranslatableFixtureItemType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('label', TextType::class, ['required' => false, 'cb_translatable' => true])
            ->add('url', TextType::class, ['required' => false]);
    }
}
