<?php

declare(strict_types=1);

namespace ContentBlocks\Kit\Tests\Block;

use ContentBlocks\Form\Extension\TranslatableFieldTypeExtension;
use ContentBlocks\Kit\Block\AbstractKitBlock;
use ContentBlocks\Kit\ContentBlocksKitBundle;
use ContentBlocks\Kit\Form\Type\RichTextEditorType;
use ContentBlocks\Kit\RichText\RichTextEditorRegistry;
use ContentBlocks\Kit\RichText\TinyMceEditor;
use ContentBlocks\Palette\ColorPaletteRegistry;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\Extension\Validator\ValidatorExtension;
use Symfony\Component\Form\Form;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\Forms;
use Symfony\Component\Form\PreloadedExtension;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Validator\Constraints\Choice;
use Symfony\Component\Validator\Validation;

/**
 * Every block's initial data has to be editable by that same block.
 *
 * `TableBlock` shipped `align: left` / `align: right` long after the RC1 rename
 * to `start`/`end` had gone through its form type and its template: a fresh
 * table opened with two columns its own `<select>` could not represent, the
 * second rendering left while announcing right. Nothing caught it, because the
 * two halves are declared in different files and nothing compared them.
 *
 * So this walks each block's real form — collections included, through their
 * `entry_type` — and confronts every choice field with the value the block
 * hands it on creation. It is the cheapest test that fails on the whole family
 * rather than on the one case that was found by hand.
 */
final class DefaultDataContractTest extends TestCase
{
    /**
     * The blocks whose coded default data names at least one choice value. The
     * seven left out declare no ChoiceType at all — they are text, URLs, and
     * collections of those.
     *
     * @var list<string>
     */
    private const TYPES_WITH_CHOICE_DEFAULTS = [
        'title', 'image', 'gallery', 'button', 'card',
        'list', 'icon', 'alert', 'divider', 'table',
    ];

    /** @return iterable<string, array{string, class-string<AbstractKitBlock>}> */
    public static function blocks(): iterable
    {
        foreach (ContentBlocksKitBundle::BLOCKS as $type => $class) {
            yield $type => [$type, $class];
        }
    }

    /** @param class-string<AbstractKitBlock> $class */
    #[DataProvider('blocks')]
    public function testEveryDefaultChoiceValueIsOfferedByItsOwnField(string $type, string $class): void
    {
        $block = new $class();
        $data = $block->getDefaultData();

        $builder = Forms::createFormFactoryBuilder()
            ->addExtension(new ValidatorExtension(Validation::createValidator()))
            // The one field type with a constructor dependency; every other is
            // instantiated by the registry straight from its class name.
            ->addExtension(new PreloadedExtension([$this->richTextEditorType()], []))
            ->addTypeExtension(new TranslatableFieldTypeExtension())
            ->getFormFactory()
            ->createBuilder(FormType::class, null, ['data_class' => null]);

        $block->buildForm($builder, $data);

        $checked = 0;
        foreach ($this->choiceFields($builder->getForm()) as $path => $allowed) {
            foreach ($this->valuesAt($data, explode('.', $path)) as $value) {
                ++$checked;
                $this->assertContains($value, $allowed, sprintf(
                    '%s: default data has %s = "%s", which its own form does not offer (%s).',
                    $class,
                    $path,
                    \is_scalar($value) ? (string) $value : get_debug_type($value),
                    implode(', ', $allowed),
                ));
            }
        }

        // Guard the guard. A block whose defaults stopped naming any choice
        // value would pass vacuously, and the day someone drops a default is
        // exactly the day this test should speak up.
        $this->assertSame(
            \in_array($type, self::TYPES_WITH_CHOICE_DEFAULTS, true),
            $checked > 0,
            sprintf('%s: %d choice default(s) checked — update TYPES_WITH_CHOICE_DEFAULTS if that is intended.', $type, $checked),
        );
    }

    private function richTextEditorType(): RichTextEditorType
    {
        $urls = $this->createStub(UrlGeneratorInterface::class);
        $urls->method('generate')->willReturn('/_content-blocks/upload');

        return new RichTextEditorType(
            new RichTextEditorRegistry([new TinyMceEditor(new ColorPaletteRegistry([]), $urls)]),
        );
    }

    /**
     * Choice values offered by each field of the form, keyed by a dotted path
     * into the block's data. A collection contributes its `entry_type`'s fields
     * under `<collection>.*.<field>`, since its default value is a list.
     *
     * Read from the field's `Assert\Choice` rather than from the ChoiceType's
     * own options: the kit derives that constraint from the full coded set on
     * purpose (restricting the picker must never invalidate stored data), so it
     * is the wider of the two — and the one a default has to satisfy.
     *
     * @return array<string, list<string>>
     */
    private function choiceFields(FormInterface $form, string $prefix = ''): array
    {
        $out = [];

        foreach ($form as $name => $child) {
            \assert($child instanceof Form);
            $path = $prefix === '' ? (string) $name : $prefix . '.' . $name;
            $config = $child->getConfig();

            foreach ($config->getOption('constraints') ?? [] as $constraint) {
                if ($constraint instanceof Choice && \is_array($constraint->choices)) {
                    $out[$path] = array_values($constraint->choices);
                }
            }

            // A collection's entries are built from its entry_type; walking the
            // prototype is what reaches `columns.*.align`.
            $entryType = $config->hasOption('entry_type') ? $config->getOption('entry_type') : null;
            if (\is_string($entryType) && class_exists($entryType)) {
                $entry = $config->getFormFactory()
                    ->createBuilder($entryType, null, ($config->getOption('entry_options') ?? []) + ['data_class' => null])
                    ->getForm();

                $out += $this->choiceFields($entry, $path . '.*');
                continue;
            }

            if (\count($child) > 0) {
                $out += $this->choiceFields($child, $path);
            }
        }

        return $out;
    }

    /**
     * Every value a dotted path resolves to in the block's default data. `*`
     * fans out over a list, so a collection with two entries yields two values.
     *
     * @param array<string, mixed> $data
     * @param list<string>         $segments
     *
     * @return list<mixed>
     */
    private function valuesAt(mixed $data, array $segments): array
    {
        if ($segments === []) {
            return $data === null ? [] : [$data];
        }

        $head = array_shift($segments);

        if ($head === '*') {
            if (!\is_array($data)) {
                return [];
            }

            $out = [];
            foreach ($data as $entry) {
                $out = [...$out, ...$this->valuesAt($entry, $segments)];
            }

            return $out;
        }

        if (!\is_array($data) || !\array_key_exists($head, $data)) {
            return [];
        }

        return $this->valuesAt($data[$head], $segments);
    }

}
