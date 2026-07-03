<?php

declare(strict_types=1);

namespace ContentBlocks\Kit\Icon;

/**
 * A small, self-contained set of inline SVG icons shipped with the kit, so the
 * `icon` block (and the alert block's per-type glyphs) need no external icon
 * library. All paths use `currentColor` so they inherit the element's color.
 *
 * The SVGs are authored here (never user input), so they are safe to render raw.
 */
final class IconSet
{
    /** @var array<string, string> name => inner SVG markup (paths only) */
    private const ICONS = [
        'star' => '<path d="m12 2 3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01z"/>',
        'heart' => '<path d="M20.8 4.6a5.5 5.5 0 0 0-7.8 0L12 5.6l-1-1a5.5 5.5 0 0 0-7.8 7.8l1 1L12 21l7.8-7.6 1-1a5.5 5.5 0 0 0 0-7.8z"/>',
        'check' => '<path d="M20 6 9 17l-5-5"/>',
        'check-circle' => '<circle cx="12" cy="12" r="10"/><path d="m9 12 2 2 4-4"/>',
        'info' => '<circle cx="12" cy="12" r="10"/><path d="M12 16v-4M12 8h.01"/>',
        'help' => '<circle cx="12" cy="12" r="10"/><path d="M9.1 9a3 3 0 0 1 5.8 1c0 2-3 3-3 3M12 17h.01"/>',
        'warning' => '<path d="M10.3 3.6 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.6a2 2 0 0 0-3.4 0z"/><path d="M12 9v4M12 17h.01"/>',
        'lightbulb' => '<path d="M9 18h6M10 22h4M12 2a7 7 0 0 0-4 12.7c.6.5 1 1.2 1 2V17h6v-.3c0-.8.4-1.5 1-2A7 7 0 0 0 12 2z"/>',
        'phone' => '<path d="M22 16.9v3a2 2 0 0 1-2.2 2 19.8 19.8 0 0 1-8.6-3 19.5 19.5 0 0 1-6-6 19.8 19.8 0 0 1-3-8.6A2 2 0 0 1 4.1 2h3a2 2 0 0 1 2 1.7c.1 1 .4 1.9.7 2.8a2 2 0 0 1-.5 2.1L8.1 9.9a16 16 0 0 0 6 6l1.3-1.3a2 2 0 0 1 2.1-.4c.9.3 1.8.5 2.8.6a2 2 0 0 1 1.7 2z"/>',
        'mail' => '<rect width="20" height="16" x="2" y="4" rx="2"/><path d="m2 7 10 6 10-6"/>',
        'map-pin' => '<path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0z"/><circle cx="12" cy="10" r="3"/>',
        'calendar' => '<rect width="18" height="18" x="3" y="4" rx="2"/><path d="M3 10h18M8 2v4M16 2v4"/>',
        'clock' => '<circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/>',
        'user' => '<circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/>',
        'settings' => '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.9l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-2.9 1.2V21a2 2 0 1 1-4 0v-.1A1.7 1.7 0 0 0 7 19.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0-1.2-2.9H3a2 2 0 1 1 0-4h.1A1.7 1.7 0 0 0 4.7 7l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.9.3H10a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 2.9 1.2l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.9V10a1.7 1.7 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1z"/>',
        'thumbs-up' => '<path d="M7 10v11H3V10zM7 10l4-8a2 2 0 0 1 3 2l-1 6h5a2 2 0 0 1 2 2.4l-1.4 7A2 2 0 0 1 18 21H7"/>',
        'gift' => '<rect width="20" height="12" x="2" y="9" rx="1"/><path d="M12 9v13M2 13h20M12 9a3 3 0 1 0-3-3c0 2 3 3 3 3zM12 9a3 3 0 1 1 3-3c0 2-3 3-3 3z"/>',
        'shield' => '<path d="M12 2 4 5v6c0 5 3.4 8.6 8 10 4.6-1.4 8-5 8-10V5z"/>',
        'zap' => '<path d="M13 2 3 14h7l-1 8 10-12h-7z"/>',
        'globe' => '<circle cx="12" cy="12" r="10"/><path d="M2 12h20M12 2a15 15 0 0 1 0 20 15 15 0 0 1 0-20z"/>',
        'camera' => '<path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/><circle cx="12" cy="13" r="4"/>',
        'arrow-right' => '<path d="M5 12h14M12 5l7 7-7 7"/>',
        'quote' => '<path d="M6 17h3l2-4V7H5v6h3zM14 17h3l2-4V7h-6v6h3z"/>',
    ];

    /**
     * @return array<string, string> name => inner SVG markup
     */
    public static function all(): array
    {
        return self::ICONS;
    }

    /**
     * Form-friendly choices: label (Title Case) => name.
     *
     * @return array<string, string>
     */
    public static function choices(): array
    {
        $out = [];
        foreach (array_keys(self::ICONS) as $name) {
            $label = ucwords(str_replace('-', ' ', $name));
            $out[$label] = $name;
        }

        return $out;
    }

    public static function names(): array
    {
        return array_keys(self::ICONS);
    }

    /** Inner SVG markup for a name, or null if unknown. */
    public static function inner(string $name): ?string
    {
        return self::ICONS[$name] ?? null;
    }

    /**
     * Full `<svg>` markup for a name (or null). Stroke style with
     * `currentColor`, sized via width/height attributes.
     */
    public static function svg(string $name, int $size = 24): ?string
    {
        $inner = self::inner($name);
        if ($inner === null) {
            return null;
        }

        return sprintf(
            '<svg xmlns="http://www.w3.org/2000/svg" width="%1$d" height="%1$d" viewBox="0 0 24 24" '
            . 'fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" '
            . 'stroke-linejoin="round">%2$s</svg>',
            $size,
            $inner,
        );
    }
}
