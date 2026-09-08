<?php

declare(strict_types=1);

namespace ContentBlocks\Kit\RichText;

use ContentBlocks\Palette\ColorPaletteRegistry;
use Symfony\Component\Asset\Packages;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Shared option handling for the shipped editors. A subclass declares only its
 * identity — name, controller, default asset URLs — and may add its own values.
 *
 * @see docs/internals/kit.md#assets-and-the-asset-prefix
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
     * Stylesheet the editor needs alongside its script, if any. TinyMCE
     * bundles its own skin and has none.
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
        // Emptied rather than dropped: the controller reads "no URL" as
        // "the host bundled the editor, expect the global".
        $cdn = (bool) ($options['cdn'] ?? true);

        return [
            'script-url' => $cdn ? $this->assetUrl($options, 'script_url', 'cdn_url', static::getDefaultScriptUrl()) : '',
            'style-url' => $cdn ? $this->assetUrl($options, 'style_url', 'cdn_style_url', static::getDefaultStyleUrl() ?? '') : '',
            // Empty upload URL is how the controller learns uploads are off —
            // one value carrying both the flag and its target.
            'upload-url' => ($options['uploads'] ?? true) ? $this->urlGenerator->generate('content_blocks_upload') : '',
            // Top level only, so `{}` rather than `[]` while a nested
            // toolbar list keeps its array shape.
            'config' => $this->encode((object) $this->resolveAssets($options['config'] ?? [])),
            'palette' => $this->encode($this->paletteColors()),
        ];
    }

    /**
     * `$key`, else the legacy `$legacyKey` it replaced, else the default.
     *
     * @see docs/internals/kit.md#assets-and-the-asset-prefix
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
     * Rewrites `asset:<path>` at any depth. A missing resolver throws rather
     * than shipping a 404 into the editor chrome.
     *
     * @see docs/internals/kit.md#assets-and-the-asset-prefix
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
            throw new \LogicException(sprintf('Cannot resolve "%s" in the rich-text editor options: no asset packages are available. Install symfony/asset and enable the "framework.assets" configuration, or give a plain URL.', $value, ));
        }

        return $this->assets->getUrl($path);
    }

    /**
     * The same named colors `PaletteColorType` offers, so swatches and colour
     * fields cannot drift apart.
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
     * JSON for a `data-*-value` attribute, degrading to an empty payload the
     * controller can still parse rather than breaking the sidebar.
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
