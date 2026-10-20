<?php

declare(strict_types=1);

namespace ContentBlocks\Kit\Headless;

use ContentBlocks\BlockType\BlockTypeRegistry;
use ContentBlocks\Headless\BlockJsonNormalizerInterface;
use ContentBlocks\Image\ImageUrlResolverInterface;
use ContentBlocks\Kit\Block\AbstractKitBlock;
use ContentBlocks\Kit\Security\SafeLink;
use ContentBlocks\Kit\Twig\EmbedExtension;
use ContentBlocks\Rendering\BlockView;
use ContentBlocks\Rendering\RenderContext;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerInterface;

/**
 * The guards the kit's views apply, for a client with no view: images through
 * the resolver, rich text sanitized, unsafe links blanked.
 *
 * @see docs/guide/headless.md#the-kit-blocks
 */
final class KitJsonNormalizer implements BlockJsonNormalizerInterface
{
    /** The widths the image view gives the resolver per size. */
    private const IMAGE_WIDTHS = ['sm' => 400, 'md' => 800, 'lg' => 1200];

    /** Collections whose entries carry a link under `url`. */
    private const LINKED_ITEMS = ['button_group', 'breadcrumb', 'card', 'gallery'];

    public function __construct(
        private readonly BlockTypeRegistry $blockTypes,
        private readonly ImageUrlResolverInterface $images,
        private readonly HtmlSanitizerInterface $richTextSanitizer,
        private readonly EmbedExtension $embeds = new EmbedExtension(),
    ) {
    }

    public function normalize(BlockView $block, array $data, RenderContext $context): array
    {
        $type = $block->type;
        if (!$this->blockTypes->has($type) || !$this->blockTypes->get($type) instanceof AbstractKitBlock) {
            return $data;
        }

        if (\in_array($type, ['button', 'image'], true)) {
            $data = self::safeLink($data, 'url');
        }
        if (\in_array($type, self::LINKED_ITEMS, true)) {
            $data = self::eachItem($data, static fn (array $item): array => self::safeLink($item, 'url'));
        }

        return match ($type) {
            'rich_text' => ['content' => $this->richTextSanitizer->sanitize(
                \is_string($data['content'] ?? null) ? $data['content'] : '',
            )] + $data,
            'image' => $this->image($data, 'src', ...self::imageBox($data)),
            'gallery', 'card' => self::eachItem($data, fn (array $item): array => $this->image($item, 'src')),
            'video' => self::safeLink($this->image($data, 'poster'), 'captions'),
            'embed' => $data + ['embedUrl' => \is_string($data['url'] ?? null)
                ? $this->embeds->buildEmbedUrl($data['url'])
                : null],
            default => $data,
        };
    }

    /**
     * `$key` resolved to the URL the view would render, beside its
     * `srcset` / `sizes`; a blank one stays blank, with no candidates.
     *
     * @param array<string, mixed> $holder
     *
     * @return array<string, mixed>
     */
    private function image(array $holder, string $key, ?int $width = null, ?int $height = null): array
    {
        $src = \is_string($holder[$key] ?? null) ? trim($holder[$key]) : '';
        $resolved = $src === '' ? null : $this->images->resolve($src, $width, $height);
        $prefix = $key === 'src' ? '' : $key;

        $holder[$key] = $resolved === null ? '' : $resolved->src;
        $holder[$prefix . ($prefix === '' ? 'srcset' : 'Srcset')] = $resolved?->srcset;
        $holder[$prefix . ($prefix === '' ? 'sizes' : 'Sizes')] = $resolved?->sizes;

        return $holder;
    }

    /**
     * The width and height the image view pins, as `[width, height]`.
     *
     * @param array<string, mixed> $data
     *
     * @return array{?int, ?int}
     */
    private static function imageBox(array $data): array
    {
        $size = $data['size'] ?? 'md';
        if ($size !== 'custom') {
            return [\is_string($size) ? self::IMAGE_WIDTHS[$size] ?? null : null, null];
        }
        $width = is_numeric($data['customWidth'] ?? null) ? abs((int) $data['customWidth']) : null;
        $pinned = ($data['customHeightAuto'] ?? true) !== true && is_numeric($data['customHeight'] ?? null);

        return [$width ?: null, $pinned ? (abs((int) $data['customHeight']) ?: null) : null];
    }

    /**
     * @param array<string, mixed> $holder
     *
     * @return array<string, mixed>
     */
    private static function safeLink(array $holder, string $key): array
    {
        if (\array_key_exists($key, $holder)) {
            $holder[$key] = SafeLink::filter(\is_string($holder[$key]) ? trim($holder[$key]) : null);
        }

        return $holder;
    }

    /**
     * @param array<string, mixed>                       $data
     * @param callable(array<string, mixed>): array<string, mixed> $map
     *
     * @return array<string, mixed>
     */
    private static function eachItem(array $data, callable $map): array
    {
        if (!\is_array($data['items'] ?? null)) {
            return $data;
        }
        foreach ($data['items'] as $i => $item) {
            if (\is_array($item)) {
                $data['items'][$i] = $map($item);
            }
        }

        return $data;
    }
}
