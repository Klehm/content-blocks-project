<?php

declare(strict_types=1);

namespace ContentBlocks\Icon;

/**
 * The icons the core's own fields use, open to any host field. Drawn on a
 * 20×20 grid, 1.5 stroke, `currentColor`.
 *
 * @see docs/guide/sidebar-fields.md#the-shipped-icons
 */
final class CoreUiIcons implements UiIconProviderInterface
{
    private const ICONS = [
        'auto' => '<path d="M5 10h10" opacity=".55"/>',
        'pos-tl' => '<rect x="3" y="3" width="14" height="14" rx="2" opacity=".35"/><rect x="5" y="5" width="4" height="4" rx="1" fill="currentColor" stroke="none"/>',
        'pos-tc' => '<rect x="3" y="3" width="14" height="14" rx="2" opacity=".35"/><rect x="8" y="5" width="4" height="4" rx="1" fill="currentColor" stroke="none"/>',
        'pos-tr' => '<rect x="3" y="3" width="14" height="14" rx="2" opacity=".35"/><rect x="11" y="5" width="4" height="4" rx="1" fill="currentColor" stroke="none"/>',
        'pos-ml' => '<rect x="3" y="3" width="14" height="14" rx="2" opacity=".35"/><rect x="5" y="8" width="4" height="4" rx="1" fill="currentColor" stroke="none"/>',
        'pos-mc' => '<rect x="3" y="3" width="14" height="14" rx="2" opacity=".35"/><rect x="8" y="8" width="4" height="4" rx="1" fill="currentColor" stroke="none"/>',
        'pos-mr' => '<rect x="3" y="3" width="14" height="14" rx="2" opacity=".35"/><rect x="11" y="8" width="4" height="4" rx="1" fill="currentColor" stroke="none"/>',
        'pos-bl' => '<rect x="3" y="3" width="14" height="14" rx="2" opacity=".35"/><rect x="5" y="11" width="4" height="4" rx="1" fill="currentColor" stroke="none"/>',
        'pos-bc' => '<rect x="3" y="3" width="14" height="14" rx="2" opacity=".35"/><rect x="8" y="11" width="4" height="4" rx="1" fill="currentColor" stroke="none"/>',
        'pos-br' => '<rect x="3" y="3" width="14" height="14" rx="2" opacity=".35"/><rect x="11" y="11" width="4" height="4" rx="1" fill="currentColor" stroke="none"/>',
        'corner-tl' => '<rect x="3" y="3" width="14" height="14" rx="2" opacity=".35"/><path d="M3 9V5a2 2 0 0 1 2-2h4" stroke-width="2.4"/>',
        'corner-tr' => '<rect x="3" y="3" width="14" height="14" rx="2" opacity=".35"/><path d="M11 3h4a2 2 0 0 1 2 2v4" stroke-width="2.4"/>',
        'corner-bl' => '<rect x="3" y="3" width="14" height="14" rx="2" opacity=".35"/><path d="M3 11v4a2 2 0 0 0 2 2h4" stroke-width="2.4"/>',
        'corner-br' => '<rect x="3" y="3" width="14" height="14" rx="2" opacity=".35"/><path d="M17 11v4a2 2 0 0 1-2 2h-4" stroke-width="2.4"/>',
        'side-top' => '<rect x="3" y="3" width="14" height="14" rx="2" opacity=".35"/><path d="M4 3h12" stroke-width="2.6"/>',
        'side-right' => '<rect x="3" y="3" width="14" height="14" rx="2" opacity=".35"/><path d="M17 4v12" stroke-width="2.6"/>',
        'side-bottom' => '<rect x="3" y="3" width="14" height="14" rx="2" opacity=".35"/><path d="M4 17h12" stroke-width="2.6"/>',
        'side-left' => '<rect x="3" y="3" width="14" height="14" rx="2" opacity=".35"/><path d="M3 4v12" stroke-width="2.6"/>',
        'side-all' => '<rect x="3" y="3" width="14" height="14" rx="2" stroke-width="2.2"/>',
        'round-tl' => '<path d="M3 17V3h14v14z" opacity=".3"/><path d="M3 12V8a5 5 0 0 1 5-5h4" stroke-width="2.2"/>',
        'round-tr' => '<path d="M3 17V3h14v14z" opacity=".3"/><path d="M8 3h4a5 5 0 0 1 5 5v4" stroke-width="2.2"/>',
        'round-bl' => '<path d="M3 17V3h14v14z" opacity=".3"/><path d="M3 8v4a5 5 0 0 0 5 5h4" stroke-width="2.2"/>',
        'round-br' => '<path d="M3 17V3h14v14z" opacity=".3"/><path d="M17 8v4a5 5 0 0 1-5 5H8" stroke-width="2.2"/>',
        'halign-start' => '<path d="M3 5h14M3 10h8M3 15h11"/>',
        'halign-center' => '<path d="M3 5h14M6 10h8M4.5 15h11"/>',
        'halign-end' => '<path d="M3 5h14M9 10h8M6 15h11"/>',
        'halign-justify' => '<path d="M3 5h14M3 10h14M3 15h14"/>',
        'valign-start' => '<path d="M3 3h14" opacity=".4"/><rect x="6" y="5.5" width="8" height="6" rx="1"/>',
        'valign-center' => '<path d="M3 10h14" opacity=".4"/><rect x="6" y="7" width="8" height="6" rx="1"/>',
        'valign-end' => '<path d="M3 17h14" opacity=".4"/><rect x="6" y="8.5" width="8" height="6" rx="1"/>',
        'valign-stretch' => '<path d="M3 3h14M3 17h14" opacity=".4"/><rect x="6" y="5.5" width="8" height="9" rx="1"/>',
        'fit-cover' => '<rect x="3" y="3" width="14" height="14" rx="2" fill="currentColor" fill-opacity=".25"/><path d="M3 14l4-4 3 3 2-2 5 5"/>',
        'fit-contain' => '<rect x="3" y="3" width="14" height="14" rx="2" opacity=".35"/><rect x="3" y="6.5" width="14" height="7" rx="1" fill="currentColor" fill-opacity=".25"/>',
        'fit-fill' => '<rect x="3" y="3" width="14" height="14" rx="2" opacity=".35"/><path d="M6 10h8M6 10l2-2M6 10l2 2M14 10l-2-2M14 10l-2 2"/>',
        'fit-none' => '<rect x="3" y="3" width="14" height="14" rx="2" opacity=".35"/><rect x="7" y="7" width="6" height="6" rx="1" fill="currentColor" fill-opacity=".25"/>',
        'ratio-1-1' => '<rect x="4.5" y="4.5" width="11" height="11" rx="1.5"/>',
        'ratio-4-3' => '<rect x="3" y="5" width="14" height="10.5" rx="1.5"/>',
        'ratio-16-9' => '<rect x="2" y="5.5" width="16" height="9" rx="1.5"/>',
        'ratio-3-4' => '<rect x="5" y="3" width="10.5" height="14" rx="1.5"/>',
        'ratio-21-9' => '<rect x="1.5" y="6.5" width="17" height="7" rx="1.5"/>',
        'dir-row' => '<rect x="2.5" y="7" width="4" height="6" rx="1"/><rect x="8" y="7" width="4" height="6" rx="1"/><rect x="13.5" y="7" width="4" height="6" rx="1"/>',
        'dir-column' => '<rect x="7" y="2.5" width="6" height="4" rx="1"/><rect x="7" y="8" width="6" height="4" rx="1"/><rect x="7" y="13.5" width="6" height="4" rx="1"/>',
        'dir-wrap' => '<rect x="2.5" y="4" width="4" height="5" rx="1"/><rect x="8" y="4" width="4" height="5" rx="1"/><rect x="13.5" y="4" width="4" height="5" rx="1"/><rect x="2.5" y="11" width="4" height="5" rx="1"/>',
        'width-full' => '<path d="M2.5 4v12M17.5 4v12"/><rect x="2.5" y="7" width="15" height="6" rx="1" fill="currentColor" fill-opacity=".25"/>',
        'width-centered' => '<path d="M2.5 4v12M17.5 4v12" stroke-dasharray="2 2"/><rect x="5.5" y="7" width="9" height="6" rx="1" fill="currentColor" fill-opacity=".25"/>',
        'media-left' => '<rect x="2.5" y="5" width="7" height="10" rx="1" fill="currentColor" fill-opacity=".25"/><path d="M12 7h5.5M12 10h5.5M12 13h3.5"/>',
        'media-right' => '<rect x="10.5" y="5" width="7" height="10" rx="1" fill="currentColor" fill-opacity=".25"/><path d="M2.5 7H8M2.5 10H8M2.5 13h3.5"/>',
        'media-top' => '<rect x="4" y="2.5" width="12" height="7" rx="1" fill="currentColor" fill-opacity=".25"/><path d="M4 12.5h12M4 15.5h8"/>',
        'display-grid' => '<rect x="2.5" y="4" width="4" height="12" rx="1"/><rect x="8" y="4" width="4" height="12" rx="1"/><rect x="13.5" y="4" width="4" height="12" rx="1"/>',
        'display-tabs' => '<path d="M2.5 7.5V4h5.5v3.5M2.5 7.5h15v9h-15z"/><path d="M8 7.5V4h5v3.5" opacity=".45"/>',
        'display-accordion' => '<rect x="2.5" y="3" width="15" height="3.5" rx="1"/><rect x="2.5" y="8.25" width="15" height="3.5" rx="1"/><rect x="2.5" y="13.5" width="15" height="3.5" rx="1"/>',
        'display-slider' => '<rect x="5.5" y="4" width="9" height="10" rx="1"/><path d="M3 7.5 1.5 9 3 10.5M17 7.5l1.5 1.5-1.5 1.5"/><path d="M8 17h.01M10 17h.01M12 17h.01" stroke-width="2"/>',
        'viewport-desktop' => '<rect x="2" y="3.5" width="16" height="10" rx="1.2"/><path d="M7 17h6M10 13.5V17"/>',
        'viewport-tablet' => '<rect x="4.5" y="2.5" width="11" height="15" rx="2"/><path d="M9.5 15h1"/>',
        'viewport-mobile' => '<rect x="6.5" y="2.5" width="7" height="15" rx="1.5"/><path d="M9.5 15h1"/>',
        'shadow-none' => '<rect x="4" y="4" width="11" height="11" rx="2"/>',
        'shadow-sm' => '<rect x="4" y="4" width="11" height="11" rx="2"/><path d="M6 16.5h9a1.5 1.5 0 0 0 1.5-1.5V6" opacity=".45"/>',
        'shadow-md' => '<rect x="3" y="3" width="11" height="11" rx="2"/><path d="M6 17h9a2 2 0 0 0 2-2V6" opacity=".45" stroke-width="2.2"/>',
        'shadow-lg' => '<rect x="6" y="6" width="11.5" height="11.5" rx="2" fill="currentColor" fill-opacity=".25" stroke="none"/><rect x="2.5" y="2.5" width="11" height="11" rx="2"/>',
        'line-solid' => '<path d="M3 10h14" stroke-width="2"/>',
        'line-dashed' => '<path d="M3 10h14" stroke-width="2" stroke-dasharray="3.5 2.5" stroke-linecap="butt"/>',
        'line-dotted' => '<path d="M3.5 10h13.5" stroke-width="2.4" stroke-dasharray=".1 3.3"/>',
        'line-double' => '<path d="M3 8.5h14M3 11.5h14"/>',
        'radius-none' => '<path d="M4 16V4h12"/>',
        'radius-sm' => '<path d="M4 16V7a3 3 0 0 1 3-3h9"/>',
        'radius-lg' => '<path d="M4 16v-5a7 7 0 0 1 7-7h5"/>',
        'radius-full' => '<rect x="2.5" y="6" width="15" height="8" rx="4"/>',
        'overlay-none' => '<rect x="3" y="3" width="14" height="14" rx="2" opacity=".35"/><path d="M4 16 16 4" opacity=".6"/>',
        'overlay-solid' => '<rect x="3" y="3" width="14" height="14" rx="2" fill="currentColor" fill-opacity=".45"/>',
        'overlay-gradient' => '<rect x="3" y="3" width="14" height="14" rx="2"/><path d="M3.8 12h12.4v2.8a1.4 1.4 0 0 1-1.4 1.4H5.2a1.4 1.4 0 0 1-1.4-1.4z" fill="currentColor" fill-opacity=".6" stroke="none"/><rect x="3.8" y="8" width="12.4" height="4" fill="currentColor" fill-opacity=".3" stroke="none"/>',
        'arrow-n' => '<g transform="rotate(0 10 10)"><path d="M10 16V4M5.5 8.5 10 4l4.5 4.5"/></g>',
        'arrow-ne' => '<g transform="rotate(45 10 10)"><path d="M10 16V4M5.5 8.5 10 4l4.5 4.5"/></g>',
        'arrow-e' => '<g transform="rotate(90 10 10)"><path d="M10 16V4M5.5 8.5 10 4l4.5 4.5"/></g>',
        'arrow-se' => '<g transform="rotate(135 10 10)"><path d="M10 16V4M5.5 8.5 10 4l4.5 4.5"/></g>',
        'arrow-s' => '<g transform="rotate(180 10 10)"><path d="M10 16V4M5.5 8.5 10 4l4.5 4.5"/></g>',
        'arrow-sw' => '<g transform="rotate(225 10 10)"><path d="M10 16V4M5.5 8.5 10 4l4.5 4.5"/></g>',
        'arrow-w' => '<g transform="rotate(270 10 10)"><path d="M10 16V4M5.5 8.5 10 4l4.5 4.5"/></g>',
        'arrow-nw' => '<g transform="rotate(315 10 10)"><path d="M10 16V4M5.5 8.5 10 4l4.5 4.5"/></g>',
    ];

    public function getIcons(): array
    {
        return self::ICONS;
    }
}
