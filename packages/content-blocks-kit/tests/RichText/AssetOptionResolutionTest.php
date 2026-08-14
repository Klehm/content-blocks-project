<?php

declare(strict_types=1);

namespace ContentBlocks\Kit\Tests\RichText;

use ContentBlocks\Kit\Block\RichTextBlock;
use ContentBlocks\Kit\RichText\CkEditor;
use ContentBlocks\Kit\RichText\TinyMceEditor;
use ContentBlocks\Palette\ColorPaletteRegistry;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Asset\Package;
use Symfony\Component\Asset\Packages;
use Symfony\Component\Asset\VersionStrategy\VersionStrategyInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * `asset:<path>` in the editor options.
 *
 * The options are static YAML and the URL of a versioned asset carries a
 * digest — `/assets/styles/wysiwyg-8f3a2c.css` — that nobody can write by
 * hand and that changes on every build. Without a resolution step there was no
 * way to point TinyMCE's `content_css` at the host's own stylesheet, which is
 * the ordinary case: an editing surface is supposed to look like the page.
 */
final class AssetOptionResolutionTest extends TestCase
{
    public function testScriptUrlIsResolvedThroughTheHostPackages(): void
    {
        $values = $this->tinymce()->buildView($this->options([
            'script_url' => 'asset:vendor/tinymce/tinymce.min.js',
        ]))->values;

        $this->assertSame('/assets/vendor/tinymce/tinymce.min-d1g3st.js', $values['script-url']);
    }

    public function testStyleUrlIsResolvedToo(): void
    {
        $values = $this->ckeditor()->buildView($this->options([
            'style_url' => 'asset:vendor/ckeditor5.css',
        ]))->values;

        $this->assertSame('/assets/vendor/ckeditor5-d1g3st.css', $values['style-url']);
    }

    public function testConfigValuesAreResolvedAtAnyDepth(): void
    {
        $config = $this->config($this->tinymce()->buildView($this->options([
            'config' => [
                // A single file, and the list form TinyMCE accepts just as
                // readily — both have to come out resolved.
                'content_css' => ['asset:styles/wysiwyg.css', 'https://cdn.example/reset.css'],
                'images' => ['bases' => ['thumb' => 'asset:img/thumb.png']],
            ],
        ]))->values);

        $this->assertSame(
            ['/assets/styles/wysiwyg-d1g3st.css', 'https://cdn.example/reset.css'],
            $config['content_css'],
        );
        $this->assertSame('/assets/img/thumb-d1g3st.png', $config['images']['bases']['thumb']);
    }

    public function testValuesWithoutThePrefixAreLeftAlone(): void
    {
        $config = $this->config($this->tinymce()->buildView($this->options([
            'config' => [
                'content_css' => '/css/plain.css',
                'menubar' => false,
                'min_height' => 400,
            ],
        ]))->values);

        $this->assertSame('/css/plain.css', $config['content_css']);
        $this->assertFalse($config['menubar']);
        $this->assertSame(400, $config['min_height']);
    }

    public function testAnAssetValueWithoutPackagesFailsLoudly(): void
    {
        // Emitting the path untouched would ship a 404 into the editor chrome,
        // where it reads as "my styles are ignored" rather than as a setup gap.
        $editor = new TinyMceEditor(new ColorPaletteRegistry([]), $this->urlGenerator(), null);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('asset:styles/wysiwyg.css');

        $editor->buildView($this->options(['config' => ['content_css' => 'asset:styles/wysiwyg.css']]));
    }

    public function testScriptUrlSupersedesTheLegacyCdnUrl(): void
    {
        $values = $this->tinymce()->buildView($this->options([
            'script_url' => '/new.js',
            'cdn_url' => '/legacy.js',
        ]))->values;

        $this->assertSame('/new.js', $values['script-url']);
    }

    public function testTheLegacyCdnUrlStillWorksOnItsOwn(): void
    {
        $values = $this->tinymce()->buildView($this->options([
            'cdn_url' => 'asset:vendor/tinymce/tinymce.min.js',
        ]))->values;

        $this->assertSame('/assets/vendor/tinymce/tinymce.min-d1g3st.js', $values['script-url']);
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function options(array $overrides): array
    {
        return array_replace(RichTextBlock::defaultOptions(), $overrides);
    }

    /**
     * @param array<string, string> $values
     *
     * @return array<string, mixed>
     */
    private function config(array $values): array
    {
        return json_decode($values['config'], true, flags: \JSON_THROW_ON_ERROR);
    }

    private function tinymce(): TinyMceEditor
    {
        return new TinyMceEditor(new ColorPaletteRegistry([]), $this->urlGenerator(), $this->packages());
    }

    private function ckeditor(): CkEditor
    {
        return new CkEditor(new ColorPaletteRegistry([]), $this->urlGenerator(), $this->packages());
    }

    private function urlGenerator(): UrlGeneratorInterface
    {
        $urls = $this->createStub(UrlGeneratorInterface::class);
        $urls->method('generate')->willReturn('/_content-blocks/upload');

        return $urls;
    }

    /** Stands in for AssetMapper: a digest injected before the extension. */
    private function packages(): Packages
    {
        return new Packages(new Package(new class implements VersionStrategyInterface {
            public function getVersion(string $path): string
            {
                return 'd1g3st';
            }

            public function applyVersion(string $path): string
            {
                return '/assets/' . preg_replace('/\.(\w+)$/', '-d1g3st.$1', $path);
            }
        }));
    }
}
