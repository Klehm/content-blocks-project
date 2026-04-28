<?php

declare(strict_types=1);

namespace ContentBlocks\Tests\Section;

use ContentBlocks\Entity\Section;
use ContentBlocks\Section\BuiltInSectionDecorator;
use ContentBlocks\Section\SectionDecoration;
use ContentBlocks\Section\SectionDecoratorCollection;
use ContentBlocks\Section\SectionDecoratorInterface;
use ContentBlocks\Section\SectionStyle;
use ContentBlocks\Section\SectionStyleProviderInterface;
use ContentBlocks\Section\SectionStyleRegistry;
use PHPUnit\Framework\TestCase;

final class SectionDecoratorTest extends TestCase
{
    public function testStyleRegistryMergesProviders(): void
    {
        $registry = new SectionStyleRegistry([
            new class implements SectionStyleProviderInterface {
                public function getStyles(): array
                {
                    return [
                        new SectionStyle('hero', 'Hero', 'app-hero'),
                        new SectionStyle('callout', 'Callout', 'app-callout'),
                    ];
                }
            },
            new class implements SectionStyleProviderInterface {
                public function getStyles(): array
                {
                    return [new SectionStyle('hero', 'Hero (overridden)', 'override-hero')];
                }
            },
        ]);

        $this->assertCount(2, $registry->all());
        // Later provider wins on name conflict.
        $this->assertSame('override-hero', $registry->get('hero')->cssClass);
        $this->assertSame('app-callout', $registry->get('callout')->cssClass);
        $this->assertSame(['Hero (overridden)' => 'hero', 'Callout' => 'callout'], $registry->getChoices());
    }

    public function testBuiltInDecoratorAppliesCustomClasses(): void
    {
        $decorator = new BuiltInSectionDecorator(new SectionStyleRegistry());
        $section = new Section();

        $deco = $decorator->decorate(['classes' => 'foo bar  baz'], $section);

        $this->assertSame('foo bar baz', $deco->classString());
        $this->assertSame('', $deco->styleString());
    }

    public function testBuiltInDecoratorAppliesCenteredWidth(): void
    {
        $decorator = new BuiltInSectionDecorator(new SectionStyleRegistry());
        $section = new Section();

        $deco = $decorator->decorate(
            ['widthMode' => 'centered', 'maxWidth' => 1100],
            $section,
        );

        $this->assertSame('cb-section--centered', $deco->classString());
        $this->assertSame('max-width:1100px;margin-left:auto;margin-right:auto;', $deco->styleString());
    }

    public function testBuiltInDecoratorIgnoresMaxWidthWhenFullWidth(): void
    {
        $decorator = new BuiltInSectionDecorator(new SectionStyleRegistry());
        $section = new Section();

        $deco = $decorator->decorate(['widthMode' => 'full', 'maxWidth' => 999], $section);

        $this->assertSame('', $deco->styleString());
        $this->assertSame('', $deco->classString());
    }

    public function testBuiltInDecoratorAppliesNamedStylePreset(): void
    {
        $registry = new SectionStyleRegistry([
            new class implements SectionStyleProviderInterface {
                public function getStyles(): array
                {
                    return [new SectionStyle('hero', 'Hero', 'cb-style-hero')];
                }
            },
        ]);
        $decorator = new BuiltInSectionDecorator($registry);
        $section = new Section();

        $deco = $decorator->decorate(['styleName' => 'hero'], $section);

        $this->assertStringContainsString('cb-style-hero', $deco->classString());
    }

    public function testBuiltInDecoratorIgnoresUnknownStyleName(): void
    {
        $registry = new SectionStyleRegistry();
        $decorator = new BuiltInSectionDecorator($registry);
        $section = new Section();

        $deco = $decorator->decorate(['styleName' => 'does-not-exist'], $section);

        $this->assertSame('', $deco->classString());
    }

    public function testCollectionMergesDecoratorsInOrder(): void
    {
        $first = new class implements SectionDecoratorInterface {
            public function decorate(array $settings, Section $section): SectionDecoration
            {
                return new SectionDecoration(
                    classes: ['a'],
                    inlineStyles: ['color' => 'red'],
                    attributes: ['data-x' => '1'],
                );
            }
        };
        $second = new class implements SectionDecoratorInterface {
            public function decorate(array $settings, Section $section): SectionDecoration
            {
                return new SectionDecoration(classes: ['b'], inlineStyles: ['color' => 'blue']);
            }
        };

        $collection = new SectionDecoratorCollection([$first, $second]);
        $deco = $collection->decorate([], new Section());

        $this->assertSame('a b', $deco->classString());
        // Later decorator wins on inlineStyles property name conflict.
        $this->assertSame('color:blue;', $deco->styleString());
        $this->assertSame(['data-x' => '1'], $deco->attributes);
    }

    public function testHostSettingExtensionFlowsThroughCollection(): void
    {
        // Sketches the host-extension contract: a custom decorator reads a
        // free-form setting key and returns a decoration. The framework
        // doesn't need to know the key in advance.
        $bgColor = new class implements SectionDecoratorInterface {
            public function decorate(array $settings, Section $section): SectionDecoration
            {
                $color = $settings['backgroundColor'] ?? null;
                if (!\is_string($color) || $color === '') {
                    return new SectionDecoration();
                }

                return new SectionDecoration(inlineStyles: ['background-color' => $color]);
            }
        };

        $collection = new SectionDecoratorCollection([
            new BuiltInSectionDecorator(new SectionStyleRegistry()),
            $bgColor,
        ]);

        $deco = $collection->decorate(
            ['classes' => 'wrap', 'backgroundColor' => '#fafafa'],
            new Section(),
        );

        $this->assertSame('wrap', $deco->classString());
        $this->assertSame('background-color:#fafafa;', $deco->styleString());
    }

    /**
     * Mirrors the host-app extension scenario implemented in the Symfony
     * sandbox (App\Form\Extension\SectionSettingsBackgroundColorExtension):
     * a Symfony FormTypeExtension on SectionSettingsType adds a custom
     * field, and that field's value lands as a free-form key in the
     * section settings JSON — to be picked up later by a decorator.
     */
    public function testHostFormExtensionAddsCustomFieldToSectionSettingsType(): void
    {
        $extension = new class extends \Symfony\Component\Form\AbstractTypeExtension {
            public static function getExtendedTypes(): iterable
            {
                return [\ContentBlocks\Form\Type\SectionSettingsType::class];
            }
            public function buildForm(\Symfony\Component\Form\FormBuilderInterface $builder, array $options): void
            {
                $builder->add('backgroundColor', \Symfony\Component\Form\Extension\Core\Type\ColorType::class, ['required' => false]);
            }
        };

        $factory = \Symfony\Component\Form\Forms::createFormFactoryBuilder()
            ->addType(new \ContentBlocks\Form\Type\SectionSettingsType(new SectionStyleRegistry()))
            ->addTypeExtension($extension)
            ->getFormFactory();

        $form = $factory->create(\ContentBlocks\Form\Type\SectionSettingsType::class);

        // Built-in fields stay…
        $this->assertTrue($form->has('classes'));
        $this->assertTrue($form->has('widthMode'));
        $this->assertTrue($form->has('maxWidth'));
        // …and the host extension's field is wired in alongside.
        $this->assertTrue($form->has('backgroundColor'));

        // Submit with a value and verify it's preserved end-to-end.
        $form->submit([
            'classes' => 'demo',
            'widthMode' => 'centered',
            'maxWidth' => 1100,
            'backgroundColor' => '#fafafa',
        ]);

        $this->assertTrue($form->isValid(), (string) $form->getErrors(true, false));
        $data = $form->getData();
        $this->assertSame('#fafafa', $data['backgroundColor']);
    }
}
