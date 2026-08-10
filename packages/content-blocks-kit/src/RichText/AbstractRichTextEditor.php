<?php

declare(strict_types=1);

namespace ContentBlocks\Kit\RichText;

use ContentBlocks\Palette\ColorPaletteRegistry;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Shared option handling for the shipped editors: where the editor's JS comes
 * from, whether uploads are wired, the host's init overrides, and the color
 * palette. Subclasses only declare their identity (name, controller, default
 * asset URLs) and may add values of their own.
 *
 * The four knobs live under `content_blocks_kit.blocks.rich_text.options`:
 *
 *     cdn: true          # false → the kit loads nothing; the host bundles the editor
 *     cdn_url: null      # null → the adapter's default URL (self-host by overriding it)
 *     uploads: true      # false → no image upload button wired
 *     config: {}         # merged over the adapter's coded init config, in the browser
 */
abstract class AbstractRichTextEditor implements RichTextEditorInterface
{
    public function __construct(
        private readonly ColorPaletteRegistry $palette,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    /**
     * The Stimulus controller that mounts this editor.
     */
    abstract public static function getController(): string;

    /**
     * Where the editor's JS is fetched from when `cdn: true` and the host has
     * not overridden `cdn_url`.
     */
    abstract public static function getDefaultScriptUrl(): string;

    /**
     * Stylesheet the editor needs alongside its script, if any. CKEditor 5
     * ships its UI CSS separately; TinyMCE bundles its own skin, so it has
     * none.
     */
    public static function getDefaultStyleUrl(): ?string
    {
        return null;
    }

    public function buildView(array $options): RichTextEditorView
    {
        return new RichTextEditorView(static::getController(), $this->buildValues($options));
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array<string, string>
     */
    protected function buildValues(array $options): array
    {
        // `cdn: false` empties both asset URLs rather than dropping them:
        // the controller reads "no URL" as "the host bundled the editor,
        // expect the global to be there already".
        $cdn = (bool) ($options['cdn'] ?? true);

        return [
            'script-url' => $cdn ? $this->assetUrl($options, 'cdn_url', static::getDefaultScriptUrl()) : '',
            'style-url' => $cdn ? $this->assetUrl($options, 'cdn_style_url', static::getDefaultStyleUrl() ?? '') : '',
            // Empty upload URL is how the controller learns uploads are off —
            // one value carrying both the flag and its target.
            'upload-url' => ($options['uploads'] ?? true) ? $this->urlGenerator->generate('content_blocks_upload') : '',
            // Cast at the top level only, so an empty config reads as `{}`
            // rather than `[]` — nested lists (a spelled-out toolbar) keep
            // their array shape.
            'config' => $this->encode((object) ($options['config'] ?? [])),
            'palette' => $this->encode($this->paletteColors()),
        ];
    }

    /**
     * @param array<string, mixed> $options
     */
    private function assetUrl(array $options, string $key, string $default): string
    {
        $override = $options[$key] ?? null;

        return \is_string($override) && $override !== '' ? $override : $default;
    }

    /**
     * The host palette as `[{label, color}]` — the same named colors
     * `PaletteColorType` offers, so an editor's swatches and a block's color
     * field cannot drift apart.
     *
     * @return list<array{label: string, color: string}>
     */
    protected function paletteColors(): array
    {
        $out = [];
        foreach ($this->palette->all() as $color) {
            $out[] = ['label' => $color->label, 'color' => $color->color];
        }

        return $out;
    }

    /**
     * JSON for a `data-*-value` attribute. Twig escapes it on output; an
     * unencodable value would otherwise blow up rendering the whole sidebar,
     * so it degrades to an empty payload the controller can still parse.
     */
    protected function encode(mixed $value): string
    {
        try {
            return json_encode($value, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
        } catch (\JsonException) {
            return \is_array($value) && array_is_list($value) ? '[]' : '{}';
        }
    }
}
