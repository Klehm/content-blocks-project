<?php

declare(strict_types=1);

namespace ContentBlocks\Kit\RichText;

use ContentBlocks\Palette\ColorPaletteRegistry;
use Symfony\Component\Asset\Packages;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Shared option handling for the shipped editors: where the editor's JS comes
 * from, whether uploads are wired, the host's init overrides, and the color
 * palette. Subclasses only declare their identity (name, controller, default
 * asset URLs) and may add values of their own.
 *
 * The knobs live under `content_blocks_kit.blocks.rich_text.options`:
 *
 *     cdn: true          # false → the kit loads nothing; the host bundles the editor
 *     script_url: null   # null → the adapter's default URL (self-host by overriding it)
 *     style_url: null    # idem for the stylesheet an editor needs alongside
 *     uploads: true      # false → no image upload button wired
 *     config: {}         # merged over the adapter's coded init config, in the browser
 *
 * Any string in there may be written `asset:<path>` and is resolved through the
 * host's asset packages — the only way a static YAML file can name a versioned
 * asset, whose URL carries a digest nobody can spell out by hand:
 *
 *     script_url: 'asset:vendor/tinymce/tinymce.min.js'
 *     config:
 *         content_css: 'asset:styles/wysiwyg.css'
 *
 * What cannot travel this way is code — `setup`, a custom button's `onAction`.
 * JSON has no function type, so those belong to the `cb-rich-text:configure`
 * event the controllers fire before init (see the kit's rich-text guide).
 */
abstract class AbstractRichTextEditor implements RichTextEditorInterface
{
    /** Marks a value as a path to run through the host's asset packages. */
    private const ASSET_PREFIX = 'asset:';

    public function __construct(
        private readonly ColorPaletteRegistry $palette,
        private readonly UrlGeneratorInterface $urlGenerator,
        // Null when the host has no asset component installed. Only an
        // `asset:` value needs it, so its absence is reported then, not here.
        private readonly ?Packages $assets = null,
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
            'script-url' => $cdn ? $this->assetUrl($options, 'script_url', 'cdn_url', static::getDefaultScriptUrl()) : '',
            'style-url' => $cdn ? $this->assetUrl($options, 'style_url', 'cdn_style_url', static::getDefaultStyleUrl() ?? '') : '',
            // Empty upload URL is how the controller learns uploads are off —
            // one value carrying both the flag and its target.
            'upload-url' => ($options['uploads'] ?? true) ? $this->urlGenerator->generate('content_blocks_upload') : '',
            // Cast at the top level only, so an empty config reads as `{}`
            // rather than `[]` — nested lists (a spelled-out toolbar) keep
            // their array shape.
            'config' => $this->encode((object) $this->resolveAssets($options['config'] ?? [])),
            'palette' => $this->encode($this->paletteColors()),
        ];
    }

    /**
     * The URL under `$key`, or under the legacy `$legacyKey` it replaced, or
     * the adapter's default. `cdn_url` / `cdn_style_url` came from a time when
     * the only override was another CDN; they still work, and they are the
     * wrong name for the self-hosted case that motivated them.
     *
     * @param array<string, mixed> $options
     */
    private function assetUrl(array $options, string $key, string $legacyKey, string $default): string
    {
        foreach ([$key, $legacyKey] as $candidate) {
            $override = $options[$candidate] ?? null;

            if (\is_string($override) && $override !== '') {
                return $this->resolveAssets($override);
            }
        }

        return $default;
    }

    /**
     * Rewrites every `asset:<path>` string into the URL the host's asset
     * packages give it, at any depth — TinyMCE's `content_css` takes a list as
     * readily as a single file, and an editor's config is free-form beyond
     * that.
     *
     * A missing resolver throws rather than emitting the path untouched: a
     * silent passthrough would ship a 404 into the editor chrome, where it
     * reads as "my styles are ignored" and not as "this needs configuring".
     */
    private function resolveAssets(mixed $value): mixed
    {
        if (\is_array($value)) {
            return array_map($this->resolveAssets(...), $value);
        }

        if (!\is_string($value) || !str_starts_with($value, self::ASSET_PREFIX)) {
            return $value;
        }

        $path = substr($value, \strlen(self::ASSET_PREFIX));

        if ($this->assets === null) {
            throw new \LogicException(sprintf(
                'Cannot resolve "%s" in the rich-text editor options: no asset packages are available. '
                . 'Install symfony/asset and enable the "framework.assets" configuration, or give a plain URL.',
                $value,
            ));
        }

        return $this->assets->getUrl($path);
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
