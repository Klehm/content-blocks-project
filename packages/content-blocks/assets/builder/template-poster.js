/**
 * A library card's thumbnail, drawn in the DOM from the server's poster
 * spec.
 *
 * @see docs/internals/frontend.md#the-builder-controller-is-split-by-feature
 * @see docs/internals/section-templates.md#why-the-poster-is-a-spec
 */
export default class TemplatePoster {
    /**
     * Draws a thumbnail from the server's poster spec, in the DOM rather than
     * rasterized. Null when there is nothing to draw.
     *
     * @see docs/internals/section-templates.md#why-the-poster-is-a-spec
     */
    _buildTemplatePoster(poster) {
        const columns = Array.isArray(poster?.columns) ? poster.columns : [];
        if (columns.length === 0) return null;

        const root = document.createElement('div');
        root.className = 'cb-template-poster';
        // Decorative: the card's name is its accessible label.
        root.setAttribute('aria-hidden', 'true');

        // Resolved server-side, preset included. `dark` comes with it, or
        // the copy disappears into its own ground.
        if (typeof poster.background === 'string' && poster.background !== '') {
            root.style.background = poster.background;
            root.classList.add('cb-template-poster--tinted');
            if (poster.dark) root.classList.add('cb-template-poster--dark');
        }

        for (const column of columns) {
            const col = document.createElement('div');
            col.className = 'cb-template-poster__col';
            // Real preset widths, so a sidebar column still reads as one.
            // Basis 0 plus grow keeps the ratio whatever the tiles measure.
            col.style.flexGrow = String(Number(column?.width) || 12);

            for (const tile of (Array.isArray(column?.tiles) ? column.tiles : [])) {
                col.appendChild(this._buildPosterTile(tile));
            }

            const more = Number(column?.more) || 0;
            if (more > 0) {
                const chip = document.createElement('span');
                chip.className = 'cb-template-poster__more';
                chip.textContent = `+${more}`;
                col.appendChild(chip);
            }

            root.appendChild(col);
        }

        return root;
    }

    /** One block, as one tile. Unknown kinds degrade to the generic chip. */
    _buildPosterTile(tile) {
        const kind = typeof tile?.kind === 'string' ? tile.kind : 'generic';
        const el = document.createElement('span');
        el.className = `cb-template-poster__tile cb-template-poster__tile--${kind}`;
        if (tile?.missing) el.classList.add('cb-template-poster__tile--missing');
        // From the core `styling` sub-form, so a section of coloured cards
        // still reads as coloured cards at thumbnail size.
        if (typeof tile?.background === 'string' && tile.background !== '') {
            el.style.background = tile.background;
            // A tile can be dark inside a light section (a red card on cream),
            // so it answers the contrast question for itself.
            el.classList.add(tile.backgroundDark
                ? 'cb-template-poster__tile--on-dark'
                : 'cb-template-poster__tile--on-light');
        }

        if (kind === 'image' && typeof tile.image === 'string' && tile.image !== '') {
            const img = document.createElement('img');
            // Ten cards' worth of thumbnails would otherwise all fetch at once
            // for a list the editor may never scroll through.
            img.loading = 'lazy';
            img.alt = '';
            // A template stores a path, not the file. The labelled tile
            // beats a broken-image glyph, which reads as the wrong fault.
            img.addEventListener('error', () => {
                img.remove();
                el.classList.remove('cb-template-poster__tile--image');
                el.classList.add('cb-template-poster__tile--generic');
                el.textContent = tile.label ?? '';
            }, { once: true });
            img.src = tile.image;
            el.appendChild(img);
            return el;
        }

        if (kind === 'rule') return el;

        // A block with no hint, or one this build lacks, names itself: an
        // empty tile would say "this block is empty", which is different.
        const named = kind === 'generic' || kind === 'image';
        const text = typeof tile?.text === 'string' && tile.text !== ''
            ? tile.text
            : (named ? (tile?.label ?? '') : '');
        el.textContent = text;
        if (text === '') el.classList.add('cb-template-poster__tile--blank');

        return el;
    }
}
