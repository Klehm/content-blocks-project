import { Controller } from '@hotwired/stimulus';

/*
 * Front-end slider for the gallery block's "slider" layout.
 *
 * Tile sizing and horizontal scroll-snap are pure CSS; JS only does what CSS
 * can't: scroll by ~one tile per arrow click, hide the arrows when the track
 * doesn't overflow, and disable the arrow that can't scroll further. Stimulus
 * (re)connects when the builder hot-swaps the block in, so no manual re-init.
 */
export default class extends Controller {
    static targets = ['track', 'prev', 'next'];

    connect() {
        if (!this.hasTrackTarget) return;
        this._update = this._update.bind(this);
        this.trackTarget.addEventListener('scroll', this._update, { passive: true });
        window.addEventListener('resize', this._update);
        this._update();
    }

    disconnect() {
        if (this.hasTrackTarget) {
            this.trackTarget.removeEventListener('scroll', this._update);
        }
        window.removeEventListener('resize', this._update);
    }

    prev() { this._scrollByTile(-1); }
    next() { this._scrollByTile(1); }

    _scrollByTile(direction) {
        const tile = this.trackTarget.querySelector('.cb-kit-gallery__item');
        const gap = parseFloat(getComputedStyle(this.trackTarget).columnGap) || 0;
        const step = tile ? tile.getBoundingClientRect().width + gap : this.trackTarget.clientWidth;
        this.trackTarget.scrollBy({ left: step * direction, behavior: 'smooth' });
    }

    _update() {
        const track = this.trackTarget;
        const overflows = track.scrollWidth > track.clientWidth + 1;
        for (const btn of [this.prevTarget, this.nextTarget]) {
            if (btn) btn.hidden = !overflows;
        }
        if (this.hasPrevTarget) this.prevTarget.disabled = track.scrollLeft <= 1;
        if (this.hasNextTarget) {
            this.nextTarget.disabled = track.scrollLeft + track.clientWidth >= track.scrollWidth - 1;
        }
    }
}
