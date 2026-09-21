<?php

declare(strict_types=1);

namespace ContentBlocks\Tests\Form;

use ContentBlocks\Form\Type\ImageUploadType;
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
     * @param list<array<string, string>> $items
     */
    private function render(array $items): \DOMXPath
    {
        $form = Forms::createFormFactoryBuilder()->getFormFactory()
            ->createNamed('gallery', GalleryFixtureType::class, ['items' => $items]);

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
        ]);
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
