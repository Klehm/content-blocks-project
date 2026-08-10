<?php

declare(strict_types=1);

namespace ContentBlocks\Kit\Tests\Block;

use ContentBlocks\Form\Extension\TranslatableFieldTypeExtension;
use ContentBlocks\Kit\Block\AlertBlock;
use ContentBlocks\Kit\Block\ButtonBlock;
use ContentBlocks\Kit\Block\EmbedBlock;
use ContentBlocks\Kit\Block\HtmlRawBlock;
use ContentBlocks\Kit\Block\RichTextBlock;
use ContentBlocks\Kit\Form\Type\AccordionItemType;
use ContentBlocks\Kit\Form\Type\BreadcrumbItemType;
use ContentBlocks\Kit\Form\Type\ListItemType;
use ContentBlocks\Kit\Form\Type\RichTextEditorType;
use ContentBlocks\Kit\Form\Type\TabEntryType;
use ContentBlocks\Kit\Form\Type\TableCellType;
use ContentBlocks\Kit\Form\Type\TableColumnType;
use ContentBlocks\Kit\RichText\RichTextEditorRegistry;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\Extension\Validator\ValidatorExtension;
use Symfony\Component\Form\Forms;
use Symfony\Component\Validator\Validation;

/**
 * The kit tags the fields an editor would want in another language, so that a
 * satellite translation package finds an annotated field set on day one rather
 * than 17 blocks nobody marked up (see the core's
 * {@see TranslatableFieldTypeExtension} for the tagging rule).
 *
 * Scope note: only blocks and item types built from stock Symfony form types
 * are exercised here — the rest pull in `PaletteColorType`, `ImageUploadType`
 * or `LiveCollectionType`, whose container dependencies are out of reach of a
 * unit test. What that costs is coverage of *which* fields those blocks tag;
 * what it does not cost is the mechanism, which is identical everywhere and
 * pinned by the cases below.
 */
final class TranslatableFieldsTest extends TestCase
{
    /**
     * @return iterable<string, array{callable(FormFactoryInterface): array<string, mixed>, list<string>}>
     */
    public static function taggedProvider(): iterable
    {
        $block = fn (object $b) => static function (FormFactoryInterface $factory) use ($b): array {
            $builder = $factory->createBuilder(FormType::class, null, ['data_class' => null]);
            $b->buildForm($builder, $b->getDefaultData());

            return iterator_to_array($builder);
        };

        $type = fn (string $class) => static fn (FormFactoryInterface $factory): array
            => iterator_to_array($factory->createBuilder($class));

        yield 'alert' => [$block(new AlertBlock()), ['title', 'content']];
        yield 'button' => [$block(new ButtonBlock()), ['text', 'url']];
        yield 'embed' => [$block(new EmbedBlock()), ['url', 'title']];
        yield 'rich_text' => [$block(new RichTextBlock()), ['content']];
        yield 'html_raw' => [$block(new HtmlRawBlock()), ['html']];

        yield 'list item' => [$type(ListItemType::class), ['text']];
        yield 'accordion item' => [$type(AccordionItemType::class), ['title', 'content']];
        yield 'tab entry' => [$type(TabEntryType::class), ['title', 'content']];
        yield 'breadcrumb item' => [$type(BreadcrumbItemType::class), ['label', 'url']];
        yield 'table column' => [$type(TableColumnType::class), ['label']];
        yield 'table cell' => [$type(TableCellType::class), ['content']];
    }

    /**
     * @param callable(FormFactoryInterface): array<string, mixed> $build
     * @param list<string>                                         $expected
     */
    #[DataProvider('taggedProvider')]
    public function testTheExpectedFieldsAreTagged(callable $build, array $expected): void
    {
        $tagged = [];
        foreach ($build($this->makeFactory()) as $name => $child) {
            if ($child->hasOption(TranslatableFieldTypeExtension::OPTION)
                && $child->getOption(TranslatableFieldTypeExtension::OPTION) === true
            ) {
                $tagged[] = (string) $name;
            }
        }

        $this->assertSame($expected, $tagged);
    }

    /**
     * Enums, sizes and colors are the same in every language. This is the half
     * that a blanket "tag everything" would quietly break — a locale payload
     * carrying `align` would be ignored at render anyway, so tagging it only
     * clutters the translation UI.
     */
    public function testStructuralFieldsAreNotTagged(): void
    {
        $button = new ButtonBlock();
        $builder = $this->makeFactory()->createBuilder(FormType::class, null, ['data_class' => null]);
        $button->buildForm($builder, $button->getDefaultData());

        foreach (['variant', 'size', 'align', 'fullWidth', 'newTab'] as $field) {
            $this->assertFalse(
                $builder->get($field)->hasOption(TranslatableFieldTypeExtension::OPTION),
                sprintf('%s is structural and must not be tagged translatable', $field),
            );
        }
    }

    private function makeFactory(): FormFactoryInterface
    {
        // Registering the extension is what makes `cb_translatable` a legal
        // option: without it every tagged field below would throw
        // UndefinedOptionsException, which is the regression this guards.
        // The validator extension comes along because kit fields declare
        // `constraints`, an option it owns.
        return Forms::createFormFactoryBuilder()
            ->addExtension(new ValidatorExtension(Validation::createValidator()))
            ->addTypeExtension(new TranslatableFieldTypeExtension())
            // `rich_text` renders through a type that takes the editor
            // registry; outside a container it has to be handed over. An
            // empty one is enough here — resolving an editor happens when the
            // view is built, and this test only inspects builders.
            ->addType(new RichTextEditorType(new RichTextEditorRegistry()))
            ->getFormFactory();
    }
}
