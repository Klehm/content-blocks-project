/**
 * Arrows, dots and autoplay for sections shown as a slider. CSS decides where
 * a section is one; this reads it back, so breakpoints live in layout.css.
 *
 * @see docs/internals/rendering.md#the-slider
 */
const instances = new WeakMap();
const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)');

function parseConfig(section) {
    try {
        const config = JSON.parse(section.getAttribute('data-cb-slider') || '{}');
        return config && typeof config === 'object' ? config : {};
    } catch (_) {
        return {};
    }
}

function label(config, key, fallback, n, count) {
    const labels = config.labels || {};
    const raw = typeof labels[key] === 'string' ? labels[key] : fallback;
    return raw.replace('%n%', String(n)).replace('%count%', String(count));
}

function makeButton(className, text, ariaLabel) {
    const button = document.createElement('button');
    button.type = 'button';
    button.className = className;
    if (text) button.textContent = text;
    button.setAttribute('aria-label', ariaLabel);
    return button;
}

class Slider {
    constructor(section) {
        this.section = section;
        this.raw = section.getAttribute('data-cb-slider');
        this.config = parseConfig(section);
        this.row = section.querySelector(':scope > .cb-row');
        this.active = false;
        this.paused = false;
        this.timer = null;
        this.frame = null;
        this.positions = [];
        this.marked = new Set();
        this.signature = '';

        const controls = this.config.controls || 'both';
        this.controls = document.createElement('div');
        this.controls.className = 'cb-slider__controls';
        if (controls === 'both' || controls === 'arrows') {
            this.prev = makeButton(
                'cb-slider__arrow cb-slider__arrow--prev',
                '‹',
                label(this.config, 'previous', 'Previous slide'),
            );
            this.next = makeButton(
                'cb-slider__arrow cb-slider__arrow--next',
                '›',
                label(this.config, 'next', 'Next slide'),
            );
            this.prev.addEventListener('click', () => this.step(-1));
            this.next.addEventListener('click', () => this.step(1));
        }
        if (controls === 'both' || controls === 'dots') {
            this.dots = document.createElement('div');
            this.dots.className = 'cb-slider__dots';
        }
        [this.prev, this.dots, this.next].forEach((node) => {
            if (node) this.controls.appendChild(node);
        });
        if (this.controls.childElementCount > 0) this.row.after(this.controls);

        this.onScroll = () => {
            if (this.frame !== null) return;
            this.frame = requestAnimationFrame(() => {
                this.frame = null;
                this.sync();
            });
        };
        this.pause = () => { this.paused = true; this.schedule(); };
        this.resume = () => { this.paused = false; this.schedule(); };
        this.onVisibility = () => this.schedule();

        this.row.addEventListener('scroll', this.onScroll, { passive: true });
        section.addEventListener('pointerenter', this.pause);
        section.addEventListener('pointerleave', this.resume);
        section.addEventListener('focusin', this.pause);
        section.addEventListener('focusout', this.resume);
        document.addEventListener('visibilitychange', this.onVisibility);
        reducedMotion.addEventListener?.('change', this.onVisibility);
        this.observer = new ResizeObserver(() => this.refresh());
        this.observer.observe(this.row);
        this.refresh();
    }

    /** What a patch can change without resizing the row. */
    currentSignature() {
        return `${this.section.className}|${this.row.childElementCount}`;
    }

    /** Live columns in visual order; hidden ones (deletions) are skipped. */
    slides() {
        return Array.from(this.row.children)
            .filter((node) => node.classList.contains('cb-col'))
            .filter((node) => node.getClientRects().length > 0)
            .sort((a, b) => a.offsetLeft - b.offsetLeft);
    }

    refresh() {
        // Re-added: a builder patch copies the server's class list over it.
        if (this.controls.childElementCount > 0) {
            this.section.classList.add('cb-slider--enhanced');
        }
        const snap = getComputedStyle(this.row).scrollSnapType;
        const active = snap.indexOf('x') !== -1;
        const slides = active ? this.slides() : [];
        if (active !== this.active) {
            this.active = active;
            this.toggleRow(active);
        }

        // One stop per slide the row can actually scroll to, the end included.
        const max = this.row.scrollWidth - this.row.clientWidth;
        const origin = slides.length > 0 ? slides[0].offsetLeft : 0;
        const stops = [];
        for (const slide of slides) {
            const left = Math.min(slide.offsetLeft - origin, max);
            if (stops.length === 0 || left - stops[stops.length - 1] > 1) {
                stops.push(left);
            }
        }
        this.positions = stops;
        this.markSlides(slides);

        this.buildDots();
        this.controls.hidden = !active || stops.length < 2;
        this.sync();
        this.schedule();
        this.signature = this.currentSignature();
    }

    toggleRow(active) {
        if (active) {
            this.row.setAttribute('role', 'region');
            this.row.setAttribute(
                'aria-roledescription',
                label(this.config, 'carousel', 'carousel'),
            );
            this.row.tabIndex = 0;
            return;
        }
        ['role', 'aria-roledescription', 'tabindex'].forEach((name) => {
            this.row.removeAttribute(name);
        });
    }

    /** Accordion panels keep their own region role and label. */
    markSlides(slides) {
        for (const slide of this.marked) {
            if (slides.includes(slide)) continue;
            slide.removeAttribute('aria-roledescription');
            if (!slide.hasAttribute('aria-labelledby')) {
                slide.removeAttribute('role');
                slide.removeAttribute('aria-label');
            }
            this.marked.delete(slide);
        }
        const roledescription = label(this.config, 'slide', 'slide');
        slides.forEach((slide, i) => {
            slide.setAttribute('aria-roledescription', roledescription);
            if (!slide.hasAttribute('aria-labelledby')) {
                slide.setAttribute('role', 'group');
                slide.setAttribute('aria-label', `${i + 1} / ${slides.length}`);
            }
            this.marked.add(slide);
        });
    }

    buildDots() {
        if (!this.dots) return;
        const count = this.positions.length;
        if (this.dots.childElementCount === count) return;
        this.dots.replaceChildren();
        for (let i = 0; i < count; i++) {
            const dot = makeButton(
                'cb-slider__dot',
                '',
                label(this.config, 'goto', 'Go to slide %n%', i + 1, count),
            );
            dot.addEventListener('click', () => this.goTo(i));
            this.dots.appendChild(dot);
        }
    }

    current() {
        const left = this.row.scrollLeft;
        let best = 0;
        this.positions.forEach((position, i) => {
            const distance = Math.abs(position - left);
            if (distance < Math.abs(this.positions[best] - left)) best = i;
        });
        return best;
    }

    sync() {
        const index = this.current();
        const last = this.positions.length - 1;
        if (this.dots) {
            Array.from(this.dots.children).forEach((dot, i) => {
                dot.setAttribute('aria-current', i === index ? 'true' : 'false');
            });
        }
        if (this.prev) this.prev.disabled = !this.config.loop && index <= 0;
        if (this.next) this.next.disabled = !this.config.loop && index >= last;
    }

    goTo(index) {
        const left = this.positions[index];
        if (left === undefined) return;
        const behavior = reducedMotion.matches ? 'auto' : 'smooth';
        this.row.scrollTo({ left, behavior });
    }

    step(delta, wrap = this.config.loop) {
        const last = this.positions.length - 1;
        let index = this.current() + delta;
        if (index > last) index = wrap ? 0 : last;
        if (index < 0) index = wrap ? last : 0;
        this.goTo(index);
    }

    /** Autoplay rewinds at the end. Never in the builder, never reduced. */
    schedule() {
        const seconds = Number(this.config.autoplay) || 0;
        const editing = this.section.closest('[data-cb-preview]') !== null;
        const run = seconds > 0 && !editing && this.active && !this.paused
            && !reducedMotion.matches && !document.hidden
            && this.positions.length > 1;
        if (!run) {
            clearInterval(this.timer);
            this.timer = null;
        } else if (this.timer === null) {
            this.timer = setInterval(() => this.step(1, true), seconds * 1000);
        }
    }

    destroy() {
        clearInterval(this.timer);
        if (this.frame !== null) cancelAnimationFrame(this.frame);
        this.observer.disconnect();
        this.row.removeEventListener('scroll', this.onScroll);
        this.section.removeEventListener('pointerenter', this.pause);
        this.section.removeEventListener('pointerleave', this.resume);
        this.section.removeEventListener('focusin', this.pause);
        this.section.removeEventListener('focusout', this.resume);
        document.removeEventListener('visibilitychange', this.onVisibility);
        reducedMotion.removeEventListener?.('change', this.onVisibility);
        this.toggleRow(false);
        this.markSlides([]);
        this.controls.remove();
        this.section.classList.remove('cb-slider--enhanced');
    }
}

/** Attaches, refreshes or detaches each section to match its attribute. */
function scan() {
    document.querySelectorAll('.cb-section').forEach((section) => {
        const instance = instances.get(section);
        const raw = section.getAttribute('data-cb-slider');
        const row = section.querySelector(':scope > .cb-row');
        if (instance && instance.raw === raw && instance.row === row) {
            if (instance.signature !== instance.currentSignature()) {
                instance.refresh();
            }
            return;
        }
        if (instance) {
            instance.destroy();
            instances.delete(section);
        }
        if (raw !== null && row) instances.set(section, new Slider(section));
    });
}

let pending = null;
function scheduleScan() {
    if (pending !== null) return;
    pending = requestAnimationFrame(() => {
        pending = null;
        scan();
    });
}

scan();
// The builder inserts and patches sections in place.
new MutationObserver(scheduleScan).observe(document.body, {
    subtree: true,
    childList: true,
    attributes: true,
    attributeFilter: ['class', 'data-cb-slider'],
});
