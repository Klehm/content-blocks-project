<?php

declare(strict_types=1);

namespace ContentBlocks\Kit\Tests\RichText;

use ContentBlocks\Form\Extension\TranslatableFieldTypeExtension;
use ContentBlocks\Kit\Block\RichTextBlock;
use ContentBlocks\Kit\Form\Type\RichTextEditorType;
use ContentBlocks\Kit\RichText\CkEditor;
use ContentBlocks\Kit\RichText\RichTextEditorRegistry;
use ContentBlocks\Kit\RichText\TinyMceEditor;
use ContentBlocks\Palette\ColorPaletteRegistry;
use PHPUnit\Framework\TestCase;
use Symfony\Bridge\Twig\Extension\FormExtension;
use Symfony\Bridge\Twig\Extension\TranslationExtension;
use Symfony\Bridge\Twig\Form\TwigRendererEngine;
use Symfony\Component\Form\Extension\Validator\ValidatorExtension;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\FormRenderer;
use Symfony\Component\Form\Forms;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Validator\Validation;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\Contracts\Translation\TranslatorTrait;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\RuntimeLoader\FactoryRuntimeLoader;

/**
 * Renders the `cb_rich_text_widget` form-theme block.
 *
 * This is the seam's other half: the PHP adapters decide *what* the browser is
 * told, this template decides *how*, and it has to do so without naming a
 * single editor — otherwise every new adapter would mean editing Twig. So the
 * assertions below are deliberately about shape (a controller name, its values,
 * its target) rather than about TinyMCE or CKEditor specifically, and the last
 * test switches editors to prove the template did not learn either name.
 */
final class RichTextWidgetRenderTest extends TestCase
{
    public function testWrapperCarriesTheAdapterControllerAndItsValues(): void
    {
        $html = $this->render(['editor' => 'tinymce']);

        $this->assertStringContainsString('data-controller="cb-tinymce"', $html);
        // The morpher must keep its hands off the DOM the editor injects.
        $this->assertStringContainsString('data-live-ignore', $html);

        foreach (['script-url', 'style-url', 'upload-url', 'config', 'palette'] as $value) {
            $this->assertStringContainsString(sprintf('data-cb-tinymce-%s-value=', $value), $html);
        }
    }

    public function testTheTextareaSurvivesAndIsTargeted(): void
    {
        $html = $this->render(['editor' => 'tinymce']);

        // The textarea is the Live binding and the no-JS fallback: it stays.
        $this->assertStringContainsString('<textarea', $html);
        $this->assertStringContainsString('data-cb-tinymce-target="textarea"', $html);
    }

    public function testStoredHtmlIsEscapedIntoTheTextarea(): void
    {
        $html = $this->render(['editor' => 'tinymce'], '<p>hello <em>world</em></p>');

        // Rendering the field must not inject the stored markup into the
        // admin DOM — the textarea holds it as text.
        $this->assertStringContainsString('&lt;p&gt;hello &lt;em&gt;world&lt;/em&gt;&lt;/p&gt;', $html);
        $this->assertStringNotContainsString('<p>hello', $html);
    }

    public function testValuesCarryTheResolvedOptions(): void
    {
        $html = $this->render([
            'editor' => 'tinymce',
            'uploads' => false,
            'config' => ['height' => 500],
        ]);

        // Uploads off renders an empty URL rather than dropping the attribute,
        // which is what the controller reads as "no image button".
        $this->assertStringContainsString('data-cb-tinymce-upload-url-value=""', $html);
        // JSON survives attribute escaping intact.
        $this->assertStringContainsString('data-cb-tinymce-config-value="{&quot;height&quot;:500}"', $html);
    }

    public function testSwitchingEditorsChangesNothingButTheNames(): void
    {
        $html = $this->render(['editor' => 'ckeditor']);

        $this->assertStringContainsString('data-controller="cb-ckeditor"', $html);
        $this->assertStringContainsString('data-cb-ckeditor-target="textarea"', $html);
        // CKEditor is the adapter that needs a stylesheet — same value slot.
        $this->assertStringContainsString('ckeditor5.css', $html);
        $this->assertStringNotContainsString('cb-tinymce', $html);
    }

    /**
     * @param array<string, mixed> $options
     */
    private function render(array $options, string $content = ''): string
    {
        $block = new RichTextBlock(array_replace(RichTextBlock::defaultOptions(), $options));

        $urls = $this->createStub(UrlGeneratorInterface::class);
        $urls->method('generate')->willReturn('/_content-blocks/upload');
        $palette = new ColorPaletteRegistry();

        $factory = Forms::createFormFactoryBuilder()
            ->addExtension(new ValidatorExtension(Validation::createValidator()))
            ->addTypeExtension(new TranslatableFieldTypeExtension())
            ->addType(new RichTextEditorType(new RichTextEditorRegistry([
                new TinyMceEditor($palette, $urls),
                new CkEditor($palette, $urls),
            ])))
            ->getFormFactory();

        $builder = $factory->createBuilder(FormType::class, null, ['data_class' => null]);
        $block->buildForm($builder, ['content' => $content]);
        $form = $builder->getForm();
        $form->setData(['content' => $content]);

        $twig = $this->makeTwig();

        return $twig->createTemplate('{{ form_widget(form.content) }}')
            ->render(['form' => $form->createView()]);
    }

    private function makeTwig(): Environment
    {
        $kitRoot = \dirname(__DIR__, 2);
        $vendor = $kitRoot . '/vendor';

        $loader = new FilesystemLoader();
        $loader->addPath($kitRoot . '/templates', 'ContentBlocksKit');
        $loader->addPath($vendor . '/klehm/content-blocks/templates', 'ContentBlocks');
        $loader->addPath($vendor . '/symfony/twig-bridge/Resources/views/Form');

        $twig = new Environment($loader);
        $twig->addExtension(new TranslationExtension($this->translator()));

        $themes = [
            'form_div_layout.html.twig',
            '@ContentBlocks/form/cb_form_theme.html.twig',
            '@ContentBlocksKit/form/rich_text_theme.html.twig',
        ];
        $engine = new TwigRendererEngine($themes, $twig);
        $twig->addRuntimeLoader(new FactoryRuntimeLoader([
            FormRenderer::class => static fn (): FormRenderer => new FormRenderer($engine),
        ]));
        $twig->addExtension(new FormExtension());

        return $twig;
    }

    private function translator(): TranslatorInterface
    {
        return new class implements TranslatorInterface {
            use TranslatorTrait;
        };
    }
}
