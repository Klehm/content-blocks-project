<?php

declare(strict_types=1);

namespace ContentBlocks\Tests\Form;

use ContentBlocks\Form\Extension\CollectionFoldTypeExtension;
use ContentBlocks\Form\Type\ImageUploadType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Bridge\Twig\Extension\FormExtension;
use Symfony\Bridge\Twig\Extension\TranslationExtension;
use Symfony\Bridge\Twig\Form\TwigRendererEngine;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormRenderer;
use Symfony\Component\Form\Forms;
use Symfony\Component\OptionsResolver\Exception\InvalidOptionsException;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\Contracts\Translation\TranslatorTrait;
use Symfony\UX\LiveComponent\Form\Type\LiveCollectionType;
use Twig\Environment;
use Twig\Loader\ArrayLoader;
use Twig\Loader\ChainLoader;
use Twig\Loader\FilesystemLoader;
use Twig\RuntimeLoader\FactoryRuntimeLoader;

/**
 * A live collection's entries fold down to a header naming them: first image,
 * first text, or their position.
 */
final class CollectionFoldRenderTest extends TestCase
{
    public function testTheHeaderShowsTheFirstImageAndTheFirstText(): void
    {
        $dom = $this->render([
            ['src' => '/uploads/kitchen.webp', 'alt' => 'Oak kitchen', 'caption' => 'x'],
            ['src' => '', 'alt' => '', 'caption' => '<p>Built-in <b>wardrobe</b></p>'],
            ['src' => '', 'alt' => '', 'caption' => ''],
        ]);

        $toggles = $dom->query('//button[contains(@class, "cb-form-collection__toggle")]');
        $this->assertCount(3, $toggles);
        $thumbs = $dom->query('.//img[@class="cb-form-collection__thumb"]', $toggles[0]);
        $this->assertSame('/uploads/kitchen.webp', $thumbs[0]?->getAttribute('src'));
        $this->assertSame(['Oak kitchen', 'Built-in wardrobe', 'cb.collection.item'], array_map(
            static fn (\DOMNode $toggle): string => trim($toggle->textContent),
            iterator_to_array($toggles),
        ));
        $this->assertSame('true', $toggles[0]->getAttribute('aria-expanded'));
        $this->assertSame('gallery_items_0', $toggles[0]->getAttribute('aria-controls'));
    }

    public function testFoldAllOnlyShowsFromTwoEntries(): void
    {
        $this->assertCount(0, $this->render([['src' => '', 'alt' => 'a', 'caption' => '']])
            ->query('//*[@data-action="cb-collection-sort#collapseAll"]'));
        $this->assertCount(1, $this->render([
            ['src' => '', 'alt' => 'a', 'caption' => ''],
            ['src' => '', 'alt' => 'b', 'caption' => ''],
        ])->query('//*[@data-action="cb-collection-sort#collapseAll"]'));
    }

    public function testTheControllerWrapsTheSortableList(): void
    {
        $dom = $this->render([['src' => '', 'alt' => 'a', 'caption' => '']]);

        $list = $dom->query('//*[@data-cb-collection-sort-target="list"]');
        $this->assertCount(1, $list);
        $this->assertSame('gallery_items', $list[0]->getAttribute('id'));
        $this->assertStringContainsString('cb-collection-sort', $list[0]->parentNode->getAttribute('data-controller'));
    }

    /**
     * @return iterable<string, array{string, list<bool>}>
     */
    public static function openEntries(): iterable
    {
        yield 'all' => ['all', [false, false, false]];
        yield 'first' => ['first', [false, true, true]];
        yield 'last' => ['last', [true, true, false]];
        yield 'none' => ['none', [true, true, true]];
    }

    /**
     * @param list<bool> $folded
     */
    #[DataProvider('openEntries')]
    public function testOpenEntriesDecidesWhichEntriesStartFolded(string $open, array $folded): void
    {
        $item = ['src' => '', 'alt' => 'a', 'caption' => ''];
        $dom = $this->render([$item, $item, $item], $open);

        $items = iterator_to_array($dom->query(
            '//div[contains(concat(" ", @class, " "), " cb-form-collection__item ")]',
        ));
        $this->assertSame($folded, array_map(
            static fn (\DOMElement $item): bool => str_contains($item->getAttribute('class'), '--collapsed'),
            $items,
        ));
        $this->assertSame(
            array_map(static fn (bool $f): string => $f ? 'false' : 'true', $folded),
            array_map(
                static fn (\DOMElement $t): string => $t->getAttribute('aria-expanded'),
                iterator_to_array($dom->query('//button[contains(@class, "cb-form-collection__toggle")]')),
            ),
        );
    }

    public function testOpenEntriesRefusesAnUnknownValue(): void
    {
        $this->expectException(InvalidOptionsException::class);
        $this->render([], 'second');
    }

    /**
     * @param list<array<string, string>> $items
     */
    private function render(array $items, string $open = 'all'): \DOMXPath
    {
        $form = Forms::createFormFactoryBuilder()
            ->addTypeExtension(new CollectionFoldTypeExtension())
            ->getFormFactory()
            ->createNamed('gallery', GalleryFixtureType::class, ['items' => $items], [
                'cb_open_entries' => $open,
            ]);

        $root = \dirname(__DIR__, 2);
        $files = new FilesystemLoader();
        $files->addPath($root . '/templates', 'ContentBlocks');
        $files->addPath($root . '/vendor/symfony/twig-bridge/Resources/views/Form');
        $twig = new Environment(new ChainLoader([new ArrayLoader([
            'page.html.twig' => "{% form_theme form '@ContentBlocks/form/cb_form_theme.html.twig' %}{{ form_widget(form) }}",
        ]), $files]), ['strict_variables' => true]);
        $twig->addExtension(new TranslationExtension(new class () implements TranslatorInterface {
            use TranslatorTrait;
        }));
        $engine = new TwigRendererEngine(['form_div_layout.html.twig'], $twig);
        $twig->addRuntimeLoader(new FactoryRuntimeLoader([
            FormRenderer::class => static fn (): FormRenderer => new FormRenderer($engine),
        ]));
        $twig->addExtension(new FormExtension());

        $document = new \DOMDocument();
        libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="UTF-8">' . $twig->render('page.html.twig', ['form' => $form->createView()]));
        libxml_clear_errors();

        return new \DOMXPath($document);
    }
}

final class GalleryFixtureType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('items', LiveCollectionType::class, [
            'entry_type' => GalleryItemFixtureType::class,
            'allow_add' => true,
            'allow_delete' => true,
            'cb_open_entries' => $options['cb_open_entries'],
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefault('cb_open_entries', 'all');
    }
}

final class GalleryItemFixtureType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('src', ImageUploadType::class)
            ->add('alt', TextType::class)
            ->add('caption', TextareaType::class);
    }
}
