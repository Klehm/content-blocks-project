<?php

declare(strict_types=1);

namespace ContentBlocks\Tests\Schema;

use ContentBlocks\Form\Type\ImageUploadType;
use ContentBlocks\Form\Type\PaletteColorType;
use ContentBlocks\Form\Type\VideoUploadType;
use ContentBlocks\Palette\ColorPaletteRegistry;
use ContentBlocks\Palette\ConfigColorPaletteProvider;
use ContentBlocks\Schema\FieldSchemaContributorInterface;
use ContentBlocks\Schema\FormSchemaMapper;
use ContentBlocks\Schema\PaletteColorFieldSchema;
use ContentBlocks\Schema\UploadFieldSchema;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ButtonType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\UrlType;
use Symfony\Component\Form\Extension\Validator\ValidatorExtension;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\Forms;
use Symfony\Component\Form\PreloadedExtension;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Validation;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The schema is read off the form that validates the data, so each case
 * here pins one way a form says what it accepts.
 */
final class FormSchemaMapperTest extends TestCase
{
    public function testATextFieldIsAStringBoundByItsLength(): void
    {
        $schema = $this->map(['data' => ['title' => 'Hi']], static function (FormBuilderInterface $b): void {
            $b->add('title', TextType::class, ['constraints' => [new Assert\Length(min: 2, max: 40)]]);
        });

        $this->assertSame(
            ['type' => 'string', 'minLength' => 2, 'maxLength' => 40, 'default' => 'Hi'],
            $schema['properties']['title'],
        );
        $this->assertFalse($schema['additionalProperties'], 'an undeclared key is dropped');
    }

    public function testAChoiceIsAnEnumOfItsModelValues(): void
    {
        $schema = $this->map([], static function (FormBuilderInterface $b): void {
            $b->add('columns', ChoiceType::class, ['choices' => ['Two' => 2, 'Three' => 3]]);
        });

        // Ints, not the "2"/"3" the browser posts: the schema is the data.
        $this->assertSame([2, 3], $schema['properties']['columns']['enum']);
    }

    public function testAnOptionalChoiceAlsoTakesItsEmptyValue(): void
    {
        $schema = $this->map([], static function (FormBuilderInterface $b): void {
            $b->add('rel', ChoiceType::class, ['choices' => ['nofollow' => 'nofollow'], 'required' => false]);
        });

        $this->assertSame(['nofollow', null], $schema['properties']['rel']['enum']);
    }

    public function testAMultipleChoiceIsAnArrayOfUniqueValues(): void
    {
        $schema = $this->map([], static function (FormBuilderInterface $b): void {
            $b->add('tags', ChoiceType::class, ['choices' => ['A' => 'a', 'B' => 'b'], 'multiple' => true]);
        });

        $this->assertSame(
            ['type' => 'array', 'items' => ['enum' => ['a', 'b']], 'uniqueItems' => true],
            $schema['properties']['tags'],
        );
    }

    public function testAChoiceConstraintNarrowsTheEnum(): void
    {
        $schema = $this->map([], static function (FormBuilderInterface $b): void {
            $b->add('size', ChoiceType::class, [
                'choices' => ['S' => 'sm', 'M' => 'md', 'L' => 'lg'],
                'constraints' => [new Assert\Choice(choices: ['sm', 'md', 'xl'])],
            ]);
        });

        $this->assertSame(['sm', 'md'], $schema['properties']['size']['enum']);
    }

    public function testScalarTypesMapToTheirJsonType(): void
    {
        $schema = $this->map([], static function (FormBuilderInterface $b): void {
            $b->add('on', CheckboxType::class)
                ->add('count', IntegerType::class, ['constraints' => [new Assert\Range(min: 1, max: 9)]])
                ->add('ratio', NumberType::class)
                ->add('link', UrlType::class);
        });

        $this->assertSame('boolean', $schema['properties']['on']['type']);
        $this->assertSame(['type' => 'integer', 'minimum' => 1, 'maximum' => 9], $schema['properties']['count']);
        $this->assertSame('number', $schema['properties']['ratio']['type']);
        $this->assertSame('uri-reference', $schema['properties']['link']['format'], 'a relative link is fine');
    }

    public function testANumberInputCarriesItsHtmlBounds(): void
    {
        $schema = $this->map([], static function (FormBuilderInterface $b): void {
            $b->add('gap', IntegerType::class, ['attr' => ['min' => 0, 'max' => 200]])
                ->add('pinned', IntegerType::class, [
                    'attr' => ['min' => 0],
                    'constraints' => [new Assert\Range(min: 10)],
                ]);
        });

        $this->assertSame(['type' => 'integer', 'minimum' => 0, 'maximum' => 200], $schema['properties']['gap']);
        $this->assertSame(10, $schema['properties']['pinned']['minimum'], 'the constraint has the last word');
    }

    public function testAnEmptyDefaultIsLeftOut(): void
    {
        // A null default beside "type": "integer" would contradict it.
        $schema = $this->map([], static function (FormBuilderInterface $b): void {
            $b->add('count', IntegerType::class);
        });

        $this->assertArrayNotHasKey('default', $schema['properties']['count']);
    }

    public function testACollectionDescribesItsEntries(): void
    {
        $schema = $this->map([], static function (FormBuilderInterface $b): void {
            $b->add('items', CollectionType::class, [
                'entry_type' => FixtureItemType::class,
                'allow_add' => true,
                'constraints' => [new Assert\Count(min: 1, max: 5)],
            ]);
        });

        $items = $schema['properties']['items'];
        $this->assertSame('array', $items['type']);
        $this->assertSame(1, $items['minItems']);
        $this->assertSame(5, $items['maxItems']);
        $this->assertSame(['label', 'url'], array_keys($items['items']['properties']));
        $this->assertArrayNotHasKey('title', $items['items'], 'not the "__name__label__" placeholder');
    }

    public function testACollectionWithoutAPrototypeStillDescribesItsEntries(): void
    {
        // The kit's live collections build no prototype.
        $schema = $this->map([], static function (FormBuilderInterface $b): void {
            $b->add('items', CollectionType::class, [
                'entry_type' => FixtureItemType::class,
                'allow_add' => true,
                'prototype' => false,
            ]);
        });

        $this->assertSame(['label', 'url'], array_keys($schema['properties']['items']['items']['properties']));
    }

    public function testANestedFormIsAClosedObject(): void
    {
        $schema = $this->map([], static function (FormBuilderInterface $b): void {
            $b->add('cta', FixtureItemType::class);
        });

        $this->assertSame('object', $schema['properties']['cta']['type']);
        $this->assertFalse($schema['properties']['cta']['additionalProperties']);
        $this->assertSame(['maxLength' => 20], array_diff_key(
            $schema['properties']['cta']['properties']['label'],
            ['type' => true],
        ));
    }

    public function testFieldsNoAuthorWritesAreLeftOut(): void
    {
        $schema = $this->map([], static function (FormBuilderInterface $b): void {
            $b->add('kept', TextType::class)
                ->add('hidden', HiddenType::class)
                ->add('unmapped', TextType::class, ['mapped' => false])
                ->add('go', ButtonType::class);
        });

        $this->assertSame(['kept'], array_keys($schema['properties']));
    }

    public function testLabelsAndHelpAreTranslated(): void
    {
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(
            static fn (string $id, array $p, ?string $domain): string => match ([$id, $domain]) {
                ['cb.field.title', 'messages'] => 'Title',
                ['cb.field.title.help', 'messages'] => 'Keep it short.',
                default => $id,
            },
        );

        $schema = $this->map([], static function (FormBuilderInterface $b): void {
            $b->add('title', TextType::class, [
                'label' => 'cb.field.title',
                'help' => 'cb.field.title.help',
                'translation_domain' => 'messages',
            ]);
        }, translator: $translator);

        $this->assertSame('Title', $schema['properties']['title']['title']);
        $this->assertSame('Keep it short.', $schema['properties']['title']['description']);
    }

    public function testAnUntranslatedKeyIsNotATitle(): void
    {
        $schema = $this->map([], static function (FormBuilderInterface $b): void {
            $b->add('a', TextType::class, ['label' => 'cb_kit.block.title.field.text'])
                ->add('b', TextType::class, ['label' => 'Plain words']);
        });

        $this->assertArrayNotHasKey('title', $schema['properties']['a'], 'a raw key teaches nothing');
        $this->assertSame('Plain words', $schema['properties']['b']['title']);
    }

    public function testAContributorDecidesItsOwnFieldType(): void
    {
        $contributor = new class () implements FieldSchemaContributorInterface {
            public function schemaFor(FormInterface $field): ?array
            {
                return $field->getName() === 'html' ? ['type' => 'string', 'contentMediaType' => 'text/html'] : null;
            }
        };

        $schema = $this->map([], static function (FormBuilderInterface $b): void {
            $b->add('html', TextType::class, ['constraints' => [new Assert\Length(max: 99)]])
                ->add('plain', TextType::class);
        }, [$contributor]);

        $this->assertSame(
            ['type' => 'string', 'contentMediaType' => 'text/html', 'maxLength' => 99],
            $schema['properties']['html'],
            'the constraints still apply on top',
        );
        $this->assertSame(['type' => 'string'], $schema['properties']['plain']);
    }

    public function testAPaletteColorIsOneStringFromThePaletteOrAHex(): void
    {
        $palette = new ColorPaletteRegistry([new ConfigColorPaletteProvider([
            ['label' => 'Brand', 'color' => '#eb0540'],
            ['label' => 'Theme', 'color' => 'var(--brand)'],
        ])]);

        $schema = $this->map(['data' => ['color' => '']], static function (FormBuilderInterface $b): void {
            $b->add('color', PaletteColorType::class)
                ->add('locked', PaletteColorType::class, ['allow_custom' => false]);
        }, [new PaletteColorFieldSchema($palette)], palette: $palette);

        $named = ['type' => 'string', 'enum' => ['', '#eb0540', 'var(--brand)']];
        $this->assertSame([$named, ['type' => 'string', 'pattern' => '^#[0-9a-fA-F]{6}$']], $schema['properties']['color']['anyOf']);
        $this->assertSame($named, array_intersect_key($schema['properties']['locked'], $named));
        $this->assertArrayNotHasKey('properties', $schema['properties']['color'], 'not its two children');
    }

    public function testAnUploadFieldIsAPathNeverToInvent(): void
    {
        $schema = $this->map([], static function (FormBuilderInterface $b): void {
            $b->add('src', ImageUploadType::class)->add('file', VideoUploadType::class);
        }, [new UploadFieldSchema()]);

        $this->assertSame('string', $schema['properties']['src']['type']);
        $this->assertStringContainsString('uploaded image', $schema['properties']['src']['description']);
        $this->assertStringContainsString('uploaded video', $schema['properties']['file']['description']);
    }

    /**
     * @param array<string, mixed>                    $options
     * @param \Closure(FormBuilderInterface): void    $build
     * @param list<FieldSchemaContributorInterface>   $contributors
     *
     * @return array<string, mixed>
     */
    private function map(
        array $options,
        \Closure $build,
        array $contributors = [],
        ?TranslatorInterface $translator = null,
        ?ColorPaletteRegistry $palette = null,
    ): array {
        $factory = Forms::createFormFactoryBuilder()
            ->addExtension(new ValidatorExtension(Validation::createValidator()))
            ->addExtension(new PreloadedExtension([new PaletteColorType($palette)], []))
            ->getFormFactory();

        $builder = $factory->createBuilder(FormType::class, $options['data'] ?? null);
        $build($builder);

        return (new FormSchemaMapper($contributors, $translator))->map($builder->getForm());
    }
}

final class FixtureItemType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('label', TextType::class, ['constraints' => [new Assert\Length(max: 20)]])
            ->add('url', UrlType::class);
    }
}
