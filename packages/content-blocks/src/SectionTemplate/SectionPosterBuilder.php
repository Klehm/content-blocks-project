<?php

declare(strict_types=1);

namespace ContentBlocks\SectionTemplate;

use ContentBlocks\BlockType\BlockPreviewHint;
use ContentBlocks\BlockType\BlockPreviewHintInterface;
use ContentBlocks\BlockType\BlockTypeRegistry;
use ContentBlocks\Section\SectionStyleRegistry;
use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Turns a stored section-template payload into a "poster": the small
 * structural description the library picker draws as a thumbnail.
 *
 * Why a spec and not an image. Rasterising the real section would mean either
 * a headless browser on every host, or a client-side canvas pass that quietly
 * loses its styling whenever the host serves CSS from another origin. The
 * payload, meanwhile, already holds the layout, the column presets, the block
 * order and the block data — enough to draw something faithful *in the DOM*,
 * where a real `<img>` is a real `<img>` and real copy is real text. It also
 * costs no column, no migration and no storage, and it works on rows that were
 * saved long before this feature existed.
 *
 * The renderer on the other end is
 * `cb-builder_controller.js#_buildTemplatePoster`; the shape returned here is
 * that contract.
 */
final class SectionPosterBuilder
{
    /**
     * Tiles drawn per column before the rest is folded into a "+N" chip. A
     * thumbnail a few hundred pixels tall cannot show more, and the cap is
     * what keeps a 60-block section from bloating the list response.
     */
    private const MAX_TILES_PER_COLUMN = 6;

    /** Fallback weight when a column carries no usable `col-N` preset. */
    private const FULL_WIDTH = 12;

    /**
     * Relative luminance above which a background counts as light. Straight
     * off the sRGB coefficients; the exact threshold matters less than having
     * one, since it only decides whether the poster paints its tiles for a
     * light or a dark ground.
     */
    private const LIGHT_LUMINANCE = 0.55;

    public function __construct(
        private readonly BlockTypeRegistry $blockTypeRegistry,
        private readonly TranslatorInterface $translator,
        private readonly SectionStyleRegistry $styleRegistry,
    ) {
    }

    /**
     * @param array<string, mixed> $payload a {@see SectionTemplateSerializer} snapshot
     *
     * @return array{layout: string, columns: list<array{width: int, tiles: list<array<string, mixed>>, more: int}>}|null
     *                                                                                                                  null when the payload holds no column structure to draw — an
     *                                                                                                                  envelope from another format, or a row written by hand. Callers
     *                                                                                                                  render the card without a thumbnail rather than an empty frame.
     */
    public function build(array $payload): ?array
    {
        $rawColumns = $payload['columns'] ?? null;
        if (!is_array($rawColumns) || $rawColumns === []) {
            return null;
        }

        $columns = [];
        foreach ($rawColumns as $rawColumn) {
            if (!is_array($rawColumn)) {
                continue;
            }
            $columns[] = $this->buildColumn($rawColumn);
        }

        if ($columns === []) {
            return null;
        }

        $layout = $payload['layout'] ?? '';
        $background = $this->sectionBackground($payload['settings'] ?? null);

        return [
            'layout' => is_string($layout) ? $layout : '',
            'columns' => $columns,
            'background' => $background,
            // Whether the tiles must be painted for a dark ground. Decided here
            // rather than in CSS because only PHP has the resolved colour, and
            // a poster whose copy is unreadable against its own background is
            // worse than one with no background at all.
            'dark' => $background !== null && !$this->isLight($background),
        ];
    }

    /**
     * Background the section actually renders with — its own, or the one its
     * style preset brings.
     *
     * The merge mirrors {@see \ContentBlocks\Rendering\BlockRenderer}: preset
     * settings sit *under* the section's, key by key, so an explicit choice
     * wins and an untouched field falls through to the preset. Without this a
     * section styled entirely by a preset would post a blank thumbnail while
     * rendering, say, dark navy.
     */
    private function sectionBackground(mixed $settings): ?string
    {
        if (!is_array($settings)) {
            return null;
        }

        $styleName = $settings['styleName'] ?? null;
        if (is_string($styleName) && $styleName !== '') {
            $preset = $this->styleRegistry->get($styleName)?->settings ?? [];
            if ($preset !== []) {
                $settings = array_replace_recursive($preset, $settings);
            }
        }

        return $this->hexOrNull($settings['styling']['backgroundColor'] ?? null);
    }

    /**
     * The one colour format the styling forms produce ({@see PaletteColorType}
     * stores a plain `#hex`, `''` meaning none). Anything else is a row from
     * elsewhere and gets ignored rather than passed into a `style` attribute.
     */
    private function hexOrNull(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^#(?:[0-9a-f]{3}|[0-9a-f]{6})$/i', $value) === 1
            ? strtolower($value)
            : null;
    }

    /** Relative luminance of a #rgb / #rrggbb colour, 0 (black) to 1 (white). */
    private function isLight(string $hex): bool
    {
        $hex = ltrim($hex, '#');
        if (\strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }

        $r = hexdec(substr($hex, 0, 2)) / 255;
        $g = hexdec(substr($hex, 2, 2)) / 255;
        $b = hexdec(substr($hex, 4, 2)) / 255;

        return (0.2126 * $r + 0.7152 * $g + 0.0722 * $b) >= self::LIGHT_LUMINANCE;
    }

    /**
     * @param array<string, mixed> $rawColumn
     *
     * @return array{width: int, tiles: list<array<string, mixed>>, more: int}
     */
    private function buildColumn(array $rawColumn): array
    {
        $rawBlocks = $rawColumn['blocks'] ?? [];
        $rawBlocks = is_array($rawBlocks) ? array_values(array_filter($rawBlocks, 'is_array')) : [];

        $tiles = [];
        foreach (\array_slice($rawBlocks, 0, self::MAX_TILES_PER_COLUMN) as $rawBlock) {
            $tiles[] = $this->buildTile($rawBlock);
        }

        return [
            'width' => $this->widthOf($rawColumn['preset'] ?? null),
            'tiles' => $tiles,
            'more' => max(0, \count($rawBlocks) - self::MAX_TILES_PER_COLUMN),
        ];
    }

    /**
     * @param array<string, mixed> $rawBlock
     *
     * @return array<string, mixed>
     */
    private function buildTile(array $rawBlock): array
    {
        $type = $rawBlock['type'] ?? null;
        $type = is_string($type) ? $type : '';
        $data = $rawBlock['data'] ?? [];
        $data = is_array($data) ? $data : [];

        // A type this build no longer registers still earns a tile: seeing
        // *where* the holes are beats a thumbnail that silently omits them.
        // list() reports the same absence in words via `skippedTypes`.
        if ($type === '' || !$this->blockTypeRegistry->has($type)) {
            return [
                'type' => $type,
                'label' => $type !== '' ? $type : '?',
                'kind' => BlockPreviewHint::KIND_GENERIC,
                'text' => null,
                'image' => null,
                'missing' => true,
                'background' => null,
                'backgroundDark' => false,
            ];
        }

        $blockType = $this->blockTypeRegistry->get($type);
        $hint = null;
        if ($blockType instanceof BlockPreviewHintInterface) {
            // A hint reads stored data of unknown age. One badly-shaped row
            // must cost that tile its detail, never the whole library.
            try {
                $hint = $blockType->previewHint($data);
            } catch (\Throwable) {
                $hint = null;
            }
        }
        $hint ??= BlockPreviewHint::generic();

        return [
            'type' => $type,
            'label' => $this->labelOf($blockType::getLabel()),
            'kind' => $hint->kind,
            'text' => $hint->text,
            'image' => $this->safeImage($hint->image),
            'missing' => false,
            // Read straight from the data, with no hint involved, because
            // `styling` is the *core's* sub-form — added to every block by
            // BlockFormType — not something a block type defines. The blocks
            // own their fields; this one the package owns.
            'background' => $background = $this->hexOrNull($data['styling']['backgroundColor'] ?? null),
            // Same reasoning as the section's own flag, one level down: a tile
            // painted its own saturated colour needs its copy flipped too, and
            // it cannot inherit the section's answer — a red card on a cream
            // section is dark ground inside a light one.
            'backgroundDark' => $background !== null && !$this->isLight($background),
        ];
    }

    /**
     * `col-4` → 4, so the poster's columns keep the proportions of the real
     * section instead of splitting evenly.
     */
    private function widthOf(mixed $preset): int
    {
        if (is_string($preset) && preg_match('/^col-(\d{1,2})$/', $preset, $m) === 1) {
            $width = (int) $m[1];
            if ($width >= 1 && $width <= self::FULL_WIDTH) {
                return $width;
            }
        }

        return self::FULL_WIDTH;
    }

    private function labelOf(string|TranslatableInterface $label): string
    {
        return $label instanceof TranslatableInterface
            ? $label->trans($this->translator)
            : $this->translator->trans($label);
    }

    /**
     * Only same-origin paths and http(s) URLs reach an `<img src>`. Stored
     * data is form-validated, but this value is about to be written straight
     * into the admin's DOM — narrowing it here is cheaper than trusting every
     * block author and every legacy row that ever wrote the field.
     */
    private function safeImage(?string $src): ?string
    {
        if ($src === null) {
            return null;
        }

        if (str_starts_with($src, '/') && !str_starts_with($src, '//')) {
            return $src;
        }

        $scheme = strtolower((string) parse_url($src, PHP_URL_SCHEME));

        return in_array($scheme, ['http', 'https'], true) ? $src : null;
    }
}
