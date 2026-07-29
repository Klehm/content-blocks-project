<?php

declare(strict_types=1);

namespace ContentBlocks\Tests\Block;

use ContentBlocks\Block\BlockDataKeys;
use ContentBlocks\BlockType\AbstractBlockType;
use ContentBlocks\BlockType\BlockTypeRegistry;
use ContentBlocks\Form\Extension\BlockFormExtensionCollection;
use ContentBlocks\Form\Extension\BlockFormExtensionInterface;
use ContentBlocks\Form\Type\BlockFormType;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\Forms;

/**
 * The union rule lives here for both restore paths (section-template insert,
 * area import), so it is worth pinning directly rather than only through them.
 */
final class BlockDataKeysTest extends TestCase
{
    private function dataKeys(?BlockFormExtensionCollection $extensions = null, bool $registered = true): BlockDataKeys
    {
        $registry = new BlockTypeRegistry();
        if ($registered) {
            $registry->register(new DataKeysFixtureBlock());
        }

        $factory = Forms::createFormFactoryBuilder()
            ->addType(new BlockFormType($extensions ?? new BlockFormExtensionCollection()))
            ->getFormFactory();

        return new BlockDataKeys($registry, $factory);
    }

    public function testAKeyNeitherDeclaredNorEditableIsUnknown(): void
    {
        $unknown = $this->dataKeys()->unknownIn('fixture', ['title' => 'x', 'legacy' => 'v']);

        $this->assertSame(['legacy'], $unknown);
    }

    public function testStylingIsKnownEvenThoughNoBlockTypeDeclaresIt(): void
    {
        // BlockFormType adds it to every block form; getDefaultData() never has
        // it. Reading defaults alone flagged it on every styled block.
        $unknown = $this->dataKeys()->unknownIn('fixture', ['title' => 'x', 'styling' => ['gap' => 1]]);

        $this->assertSame([], $unknown);
    }

    public function testAFieldContributedByAHostExtensionIsKnown(): void
    {
        $extension = new class implements BlockFormExtensionInterface {
            public function buildForm(FormBuilderInterface $builder, array $data, string $blockType): void
            {
                $builder->add('anchorId', TextType::class, ['required' => false]);
            }
        };

        $unknown = $this->dataKeys(new BlockFormExtensionCollection([[$extension, ['*']]]))
            ->unknownIn('fixture', ['title' => 'x', 'anchorId' => 'cta']);

        $this->assertSame([], $unknown);
    }

    public function testAKeyDeclaredInDefaultsButNotEditableIsStillKnown(): void
    {
        // The other half of the union: the fixture declares `internalRef` in
        // getDefaultData() without exposing it as a field. Reading the form
        // alone would have flagged it.
        $unknown = $this->dataKeys()->unknownIn('fixture', ['internalRef' => 'abc']);

        $this->assertSame([], $unknown);
    }

    public function testAnUnregisteredTypeReportsNothing(): void
    {
        // No shape to compare against. The caller decides what an unknown type
        // means — the template flow refuses it, the import flow warns.
        $unknown = $this->dataKeys(registered: false)->unknownIn('fixture', ['anything' => 1]);

        $this->assertSame([], $unknown);
    }
}

final class DataKeysFixtureBlock extends AbstractBlockType
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
        $builder->add('title', TextType::class, ['required' => false]);
    }

    public function getDefaultData(): array
    {
        // `internalRef` is declared but has no form field — stored, not editable.
        return ['title' => '', 'internalRef' => ''];
    }
}
