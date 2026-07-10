import { describe, it, expect } from 'vitest';
import { buildColorMap } from '../controllers/cb-tinymce_controller.js';

/**
 * Unit tests for the TinyMCE color_map builder. The DOM-heavy parts of the
 * controller (aux re-parenting, editor init) need a real TinyMCE and dialog,
 * so they're covered by the builder e2e suite; the palette→color_map
 * transform is pure and tested here.
 */
describe('cb-tinymce buildColorMap', () => {
    it('prepends palette colors (hex without #) then the web palette', () => {
        const map = buildColorMap([
            { label: 'Primary', color: '#eb0540' },
            { label: 'Dark', color: '#252525' },
        ]);

        // Flat [hex, label, hex, label, …] format; palette first.
        expect(map.slice(0, 4)).toEqual(['eb0540', 'Primary', '252525', 'Dark']);
        // Web palette still present afterwards.
        expect(map).toContain('Black');
        expect(map).toContain('White');
    });

    it('falls back to the web palette when no palette is given', () => {
        const map = buildColorMap(null);
        expect(map).toContain('Black');
        // No leading theme swatches.
        expect(map[0]).toBe('BFEDD2');
    });

    it('skips entries with an empty color and labels missing labels by hex', () => {
        const map = buildColorMap([
            { label: 'Nope', color: '' },
            { color: '#00ff00' },
        ]);

        expect(map).not.toContain('Nope');
        // Missing label → labeled by its hex.
        const idx = map.indexOf('00ff00');
        expect(idx).toBe(0);
        expect(map[idx + 1]).toBe('00ff00');
    });
});
