<?php

declare(strict_types=1);

namespace ContentBlocks\Kit\Tests\Block;

use ContentBlocks\Form\Extension\CollectionFoldTypeExtension;
use ContentBlocks\Form\Extension\TranslatableFieldTypeExtension;
use ContentBlocks\Kit\ContentBlocksKitBundle;
use ContentBlocks\Kit\Form\Type\RichTextEditorType;
use ContentBlocks\Kit\RichText\RichTextEditorRegistry;
use ContentBlocks\Kit\RichText\TinyMceEditor;
use ContentBlocks\Palette\ColorPaletteRegistry;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\Extension\Validator\ValidatorExtension;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\Forms;
use Symfony\Component\Form\PreloadedExtension;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Validator\Validation;
use Symfony\UX\LiveComponent\Form\Type\LiveCollectionType;

/**
 * Every kit collection, nested ones included, opens with its entries folded.
 */
final class CollectionFoldTest extends TestCase
{
    public function testEveryKitCollectionStartsFolded(): void
    {
        $urls = $this->createStub(UrlGeneratorInterface::class);
        $urls->method('generate')->willReturn('/_content-blocks/upload');
        $factory = Forms::createFormFactoryBuilder()
            ->addExtension(new ValidatorExtension(Validation::createValidator()))
            ->addExtension(new PreloadedExtension([new RichTextEditorType(
                new RichTextEditorRegistry([
                    new TinyMceEditor(new ColorPaletteRegistry([]), $urls),
                ]),
            )], []))
            ->addTypeExtension(new TranslatableFieldTypeExtension())
            ->addTypeExtension(new CollectionFoldTypeExtension())
            ->getFormFactory();

        $found = [];
        foreach (ContentBlocksKitBundle::BLOCKS as $type => $class) {
            $block = new $class();
            $builder = $factory->createBuilder(FormType::class, null, ['data_class' => null]);
            $block->buildForm($builder, $block->getDefaultData());
            $found += $this->collections($builder->getForm(), $type);
        }

        $this->assertNotEmpty($found);
        $this->assertSame(array_fill_keys(array_keys($found), 'none'), $found);
    }

    /**
     * @return array<string, string> `cb_open_entries` by collection path
     */
    private function collections(FormInterface $form, string $path): array
    {
        $out = [];
        foreach ($form as $name => $child) {
            $config = $child->getConfig();
            $at = $path . '.' . $name;
            if ($config->getType()->getInnerType() instanceof LiveCollectionType) {
                $out[$at] = $config->getOption('cb_open_entries');
                $entry = $config->getFormFactory()->createBuilder(
                    $config->getOption('entry_type'),
                    null,
                    $config->getOption('entry_options') + ['data_class' => null],
                )->getForm();
                $out += $this->collections($entry, $at . '.*');
                continue;
            }
            $out += $this->collections($child, $at);
        }

        return $out;
    }
}
