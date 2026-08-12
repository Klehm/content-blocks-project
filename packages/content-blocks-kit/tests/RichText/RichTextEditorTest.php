<?php

declare(strict_types=1);

namespace ContentBlocks\Kit\Tests\RichText;

use ContentBlocks\Kit\Block\RichTextBlock;
use ContentBlocks\Kit\RichText\AbstractRichTextEditor;
use ContentBlocks\Kit\RichText\CkEditor;
use ContentBlocks\Kit\RichText\RichTextEditorInterface;
use ContentBlocks\Kit\RichText\RichTextEditorRegistry;
use ContentBlocks\Kit\RichText\TinyMceEditor;
use ContentBlocks\Palette\ColorPaletteProviderInterface;
use ContentBlocks\Palette\ColorPaletteRegistry;
use ContentBlocks\Palette\PaletteColor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * The PHP half of the rich-text editor seam: what each adapter puts on the
 * wrapper element, and how the registry resolves `options.editor`.
 *
 * What the browser does with those values is covered by the Vitest suites;
 * what matters here is that the same option set produces the same contract
 * for every editor, so the form theme can stay editor-agnostic.
 */
final class RichTextEditorTest extends TestCase
{
    private function urlGenerator(): UrlGeneratorInterface
    {
        $urls = $this->createStub(UrlGeneratorInterface::class);
        $urls->method('generate')->willReturn('/_content-blocks/upload');

        return $urls;
    }

    private function palette(PaletteColor ...$colors): ColorPaletteRegistry
    {
        $provider = new class($colors) implements ColorPaletteProviderInterface {
            /** @param list<PaletteColor> $colors */
            public function __construct(private readonly array $colors)
            {
            }

            public function getColors(): array
            {
                return $this->colors;
            }
        };

        return new ColorPaletteRegistry([$provider]);
    }

    private function tinymce(PaletteColor ...$colors): TinyMceEditor
    {
        return new TinyMceEditor($this->palette(...$colors), $this->urlGenerator());
    }

    private function ckeditor(PaletteColor ...$colors): CkEditor
    {
        return new CkEditor($this->palette(...$colors), $this->urlGenerator());
    }

    private function editor(string $name): RichTextEditorInterface
    {
        return $name === 'tinymce' ? $this->tinymce() : $this->ckeditor();
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function shippedEditors(): iterable
    {
        yield 'tinymce' => ['tinymce', 'cb-tinymce'];
        yield 'ckeditor' => ['ckeditor', 'cb-ckeditor'];
    }

    #[DataProvider('shippedEditors')]
    public function testEachShippedEditorNamesItsController(string $name, string $controller): void
    {
        $editor = $this->editor($name);

        $this->assertSame($name, $editor::getName());
        $this->assertSame($controller, $editor->buildView(RichTextBlock::defaultOptions())->controller);
    }

    #[DataProvider('shippedEditors')]
    public function testEveryEditorAnswersTheSameValueContract(string $name, string $controller): void
    {
        $values = $this->editor($name)->buildView(RichTextBlock::defaultOptions())->values;

        // The form theme renders whatever keys it is given, so this is the
        // contract a host-written adapter can rely on being enough.
        $this->assertSame(
            ['script-url', 'style-url', 'upload-url', 'config', 'palette'],
            array_keys($values),
        );
        foreach ($values as $key => $value) {
            $this->assertIsString($value, sprintf('%s must render as a string', $key));
        }
    }

    public function testDefaultsLoadFromTheCdnWithUploadsWired(): void
    {
        $values = $this->tinymce()->buildView(RichTextBlock::defaultOptions())->values;

        $this->assertSame(TinyMceEditor::getDefaultScriptUrl(), $values['script-url']);
        $this->assertSame('/_content-blocks/upload', $values['upload-url']);
        $this->assertSame('{}', $values['config']);
    }

    public function testCkEditorAlsoShipsAStylesheetUrl(): void
    {
        $values = $this->ckeditor()->buildView(RichTextBlock::defaultOptions())->values;

        $this->assertStringContainsString(CkEditor::CDN_VERSION, $values['script-url']);
        $this->assertStringContainsString('ckeditor5.css', $values['style-url']);
        // TinyMCE bundles its own skin — no second asset.
        $this->assertSame('', $this->tinymce()->buildView(RichTextBlock::defaultOptions())->values['style-url']);
    }

    public function testCdnFalseEmptiesBothAssetUrls(): void
    {
        $options = array_replace(RichTextBlock::defaultOptions(), ['cdn' => false]);

        foreach ([$this->tinymce(), $this->ckeditor()] as $editor) {
            $values = $editor->buildView($options)->values;

            // Empty is the "the host bundled it" signal the controller reads.
            $this->assertSame('', $values['script-url']);
            $this->assertSame('', $values['style-url']);
            // Uploads are unaffected by how the editor is loaded.
            $this->assertSame('/_content-blocks/upload', $values['upload-url']);
        }
    }

    public function testHostCanPointAtItsOwnBuild(): void
    {
        $options = array_replace(RichTextBlock::defaultOptions(), [
            'cdn_url' => '/assets/vendor/ckeditor5.umd.js',
            'cdn_style_url' => '/assets/vendor/ckeditor5.css',
        ]);

        $values = $this->ckeditor()->buildView($options)->values;

        $this->assertSame('/assets/vendor/ckeditor5.umd.js', $values['script-url']);
        $this->assertSame('/assets/vendor/ckeditor5.css', $values['style-url']);
    }

    public function testAnEmptyOverrideFallsBackToTheDefaultUrl(): void
    {
        $options = array_replace(RichTextBlock::defaultOptions(), ['cdn_url' => '']);

        $this->assertSame(
            TinyMceEditor::getDefaultScriptUrl(),
            $this->tinymce()->buildView($options)->values['script-url'],
        );
    }

    public function testUploadsFalseEmptiesTheUploadUrl(): void
    {
        $options = array_replace(RichTextBlock::defaultOptions(), ['uploads' => false]);

        $this->assertSame('', $this->tinymce()->buildView($options)->values['upload-url']);
    }

    public function testHostConfigIsHandedOverAsJson(): void
    {
        $options = array_replace(RichTextBlock::defaultOptions(), [
            'config' => ['height' => 500, 'toolbar' => 'bold italic'],
        ]);

        $this->assertSame(
            '{"height":500,"toolbar":"bold italic"}',
            $this->tinymce()->buildView($options)->values['config'],
        );
    }

    public function testPaletteIsHandedOverForSwatches(): void
    {
        $editor = $this->tinymce(new PaletteColor('Primary', '#eb0540'));

        $this->assertSame(
            '[{"label":"Primary","color":"#eb0540"}]',
            $editor->buildView(RichTextBlock::defaultOptions())->values['palette'],
        );
    }

    public function testUnencodableConfigDegradesInsteadOfBreakingTheSidebar(): void
    {
        $options = array_replace(RichTextBlock::defaultOptions(), [
            // Invalid UTF-8 — JSON_THROW_ON_ERROR would take the whole form down.
            'config' => ['label' => "\xB1\x31"],
        ]);

        $this->assertSame('{}', $this->tinymce()->buildView($options)->values['config']);
    }

    public function testOptionsAreReadDefensivelyWhenTheBlockWasBuiltWithout(): void
    {
        // A host constructing the block directly, or an older config that
        // predates these options: every knob falls back to its default.
        $values = $this->tinymce()->buildView([])->values;

        $this->assertSame(TinyMceEditor::getDefaultScriptUrl(), $values['script-url']);
        $this->assertSame('/_content-blocks/upload', $values['upload-url']);
    }

    public function testRegistryResolvesByName(): void
    {
        $registry = new RichTextEditorRegistry([$this->tinymce(), $this->ckeditor()]);

        $this->assertSame(['tinymce', 'ckeditor'], $registry->names());
        $this->assertInstanceOf(TinyMceEditor::class, $registry->get('tinymce'));
        $this->assertInstanceOf(CkEditor::class, $registry->get('ckeditor'));
        $this->assertTrue($registry->has('ckeditor'));
        $this->assertFalse($registry->has('quill'));
    }

    public function testRegistryNamesTheAvailableEditorsWhenOneIsMissing(): void
    {
        $registry = new RichTextEditorRegistry([$this->tinymce()]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown rich-text editor "quill". Available: tinymce.');

        $registry->get('quill');
    }

    public function testAHostEditorRegistersLikeAShippedOne(): void
    {
        $custom = new class($this->palette(), $this->urlGenerator()) extends AbstractRichTextEditor {
            public static function getName(): string
            {
                return 'quill';
            }

            public static function getController(): string
            {
                return 'app-quill';
            }

            public static function getDefaultScriptUrl(): string
            {
                return 'https://cdn.example/quill.js';
            }
        };

        $registry = new RichTextEditorRegistry([$this->tinymce(), $custom]);
        $view = $registry->get('quill')->buildView(RichTextBlock::defaultOptions());

        $this->assertSame('app-quill', $view->controller);
        // It inherits the whole contract without writing any of it.
        $this->assertSame('/_content-blocks/upload', $view->values['upload-url']);
    }

    public function testALaterEditorWinsItsName(): void
    {
        $replacement = new class($this->palette(), $this->urlGenerator()) extends AbstractRichTextEditor {
            public static function getName(): string
            {
                return 'tinymce';
            }

            public static function getController(): string
            {
                return 'app-tinymce';
            }

            public static function getDefaultScriptUrl(): string
            {
                return '/assets/tinymce.js';
            }
        };

        $registry = new RichTextEditorRegistry([$this->tinymce(), $replacement]);

        $this->assertSame(
            'app-tinymce',
            $registry->get('tinymce')->buildView(RichTextBlock::defaultOptions())->controller,
        );
    }
}
