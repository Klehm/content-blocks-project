/**
 * Translation workbench.
 *
 * A self-contained ES module rather than a Stimulus controller: the workbench is
 * a standalone page this package renders in full, so the host's JavaScript
 * bundle — and with it the Stimulus application — is never loaded. See
 * AssetController for the reasoning.
 *
 * Three behaviours are worth reading the code for, because they are the ones the
 * design brief pinned:
 *
 *  1. **Scroll-to-field.** The preview is same-origin (it is the host's own
 *     page), so following the focused field is a direct `scrollIntoView` on the
 *     iframe's document — no postMessage protocol, no handshake.
 *  2. **Inline reload only.** After a save, exactly one block is re-fetched and
 *     its `outerHTML` swapped. The iframe never reloads, so scroll position,
 *     carousels and anything else stateful survive an edit.
 *  3. **Hideable preview.** Collapsing stops the fetches as well as the pixels,
 *     and the choice is remembered.
 */

const SAVE_DEBOUNCE_MS = 600;

class Workbench {
    constructor(root) {
        this.root = root;
        this.areaId = root.dataset.cbI18nWorkbenchAreaIdValue;
        this.locale = root.dataset.cbI18nWorkbenchLocaleValue;
        this.csrf = root.dataset.cbCsrfToken;

        this.list = root.querySelector('[data-target="list"]');
        this.previewPane = root.querySelector('[data-target="previewPane"]');
        this.preview = root.querySelector('[data-target="preview"]');
        this.toast = root.querySelector('[data-target="toast"]');
        this.providerInput = root.querySelector('[data-target="provider"]');

        /** @type {Map<string, {values: Object, timer: number}>} pending saves, keyed by block id */
        this.pending = new Map();
        this.filter = 'all';

        this._bind();
        this._restorePreferences();
    }

    // ---- wiring -----------------------------------------------------------

    _bind() {
        this.root.addEventListener('click', (event) => {
            const button = event.target.closest('[data-act]');
            if (!button || !this.root.contains(button)) return;

            const act = button.dataset.act;
            if (act === 'filter') this._applyFilter(button);
            else if (act === 'togglePreview') this.togglePreview();
            else if (act === 'translateAll') this.translateAll();
            else if (act === 'translateField') this.translateField(this._rowOf(button));
            else if (act === 'approve') this.approve(this._rowOf(button));
            else if (act === 'reset') this.reset(this._rowOf(button));
        });

        this.root.addEventListener('input', (event) => {
            const input = event.target.closest('[data-target="input"]');
            if (input) this.edit(this._rowOf(input), input.value);
        });

        // `focusin` rather than `focus`: focus does not bubble, and delegating
        // is what keeps this working for rows added after first paint.
        this.root.addEventListener('focusin', (event) => {
            const input = event.target.closest('[data-target="input"]');
            if (input) this.focusField(this._rowOf(input));
        });

        const localeSelect = this.root.querySelector('[data-act="switchLocale"]');
        if (localeSelect) {
            localeSelect.addEventListener('change', (event) => {
                window.location.href = window.location.pathname.replace(
                    /\/[^/]+$/,
                    '/' + encodeURIComponent(event.target.value),
                );
            });
        }

        // A pending debounce must not be lost to a navigation — the edit is
        // already on screen, and an editor who typed it considers it saved.
        window.addEventListener('beforeunload', () => this._flushAll(true));

        this.preview?.addEventListener('load', () => this._dressPreview());
    }

    _rowOf(element) {
        return element.closest('[data-target="row"]');
    }

    /**
     * Endpoint URLs come from the DOM, never from string concatenation here.
     *
     * The routes are the package's, but where they are *mounted* is the host's
     * call — under `/admin`, behind a firewall, anywhere. Twig generated each
     * one on the block it belongs to, so this file has no idea what the paths
     * look like and cannot go stale when they move.
     */
    _url(blockId, name) {
        return this.root.querySelector(`[data-cb-block="${blockId}"]`)?.dataset[name] ?? null;
    }

    // ---- editing ----------------------------------------------------------

    /**
     * Queues a value for its block, debounced.
     *
     * Batched per block rather than per field because the save endpoint takes a
     * batch: a translator tabbing through the four fields of a card produces one
     * request, not four.
     */
    edit(row, value) {
        if (!row) return;

        const blockId = row.dataset.block;
        const entry = this.pending.get(blockId) ?? { values: {}, timer: 0 };

        entry.values[row.dataset.path] = value;
        window.clearTimeout(entry.timer);
        entry.timer = window.setTimeout(() => this._flush(blockId), SAVE_DEBOUNCE_MS);

        this.pending.set(blockId, entry);
        this._markDirty(row);
    }

    /**
     * Clears a translation so the field falls back to its source.
     *
     * Sends `null`, which is a different thing from an empty string: `""` stores
     * a deliberate blank ("this card has no subtitle in German"), `null` removes
     * the translation entirely. Collapsing the two would make it impossible to
     * say the first without the source leaking onto the page.
     */
    reset(row) {
        if (!row) return;

        const input = row.querySelector('[data-target="input"]');
        if (input) input.value = '';

        const blockId = row.dataset.block;
        const entry = this.pending.get(blockId) ?? { values: {}, timer: 0 };
        entry.values[row.dataset.path] = null;
        this.pending.set(blockId, entry);

        window.clearTimeout(entry.timer);
        this._flush(blockId);
    }

    async approve(row) {
        if (!row) return;

        const response = await this._post(
            this._url(row.dataset.block, 'cbApproveUrl'),
            { paths: [row.dataset.path] },
        );

        if (response) {
            this._applyBlockState(response.block);
            this._toast(this._message('saved'));
        }
    }

    async _flush(blockId) {
        const entry = this.pending.get(blockId);
        if (!entry) return;

        this.pending.delete(blockId);
        window.clearTimeout(entry.timer);

        const response = await this._post(
            this._url(blockId, 'cbSaveUrl'),
            { values: entry.values },
        );

        if (!response) return;

        this._applyBlockState(response.block);
        this._refreshBlockPreview(blockId);
    }

    /** Best-effort save on unload; `keepalive` is what lets it outlive the page. */
    _flushAll(sync = false) {
        for (const [blockId, entry] of this.pending) {
            window.clearTimeout(entry.timer);

            if (!sync) {
                this._flush(blockId);
                continue;
            }

            const url = this._url(blockId, 'cbSaveUrl');
            if (!url) continue;

            fetch(url, {
                method: 'POST',
                keepalive: true,
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': this.csrf },
                body: JSON.stringify({ values: entry.values }),
            }).catch(() => {});
        }

        this.pending.clear();
    }

    // ---- machine translation ---------------------------------------------

    async translateField(row) {
        if (!row) return;

        this._toast(this._message('translating'));

        const response = await this._post(
            this._url(row.dataset.block, 'cbTranslateUrl'),
            { paths: [row.dataset.path], provider: this._provider() },
            'translate_failed',
        );

        if (!response) return;

        this._applyBlockState(response.block);
        this._refreshBlockPreview(row.dataset.block);
        this._reportFailures(response.result);
    }

    async translateAll() {
        if (!window.confirm(this._message('translate_all_confirm'))) return;

        this._toast(this._message('translating'));

        const response = await this._post(
            this.root.dataset.cbTranslateAllUrl,
            { provider: this._provider() },
            'translate_failed',
        );

        if (!response) return;

        // A whole-page run touches most rows, so reloading the list is both
        // simpler and less work than reconciling every row by hand.
        window.location.reload();
    }

    _provider() {
        return this.providerInput ? this.providerInput.value : null;
    }

    _reportFailures(result) {
        const failures = Object.values(result?.failed ?? {});

        if (failures.length > 0) {
            this._toast(`${this._message('translate_failed')} — ${failures[0]}`, true);
        }
    }

    // ---- preview ----------------------------------------------------------

    /**
     * Scrolls the preview to the block being edited and highlights it.
     *
     * Deliberately one-way: the preview follows the list, never the reverse.
     * Linking both scroll positions sounds symmetrical and is not — the panes
     * have unrelated heights, the feedback loop needs damping, and a
     * translator's attention is in the list.
     */
    focusField(row) {
        if (!row || this._previewHidden()) return;

        const doc = this._previewDocument();
        if (!doc) return;

        const target = doc.querySelector(`[data-cb-block-id="${row.dataset.block}"]`);
        if (!target) return;

        doc.querySelectorAll('.cb-wb-focus').forEach((el) => el.classList.remove('cb-wb-focus'));
        target.classList.add('cb-wb-focus');
        target.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }

    /**
     * Re-renders exactly one block in the preview.
     *
     * This is the requirement that shaped the endpoint: no iframe reload, so
     * scroll position and any JS state in the preview survive an edit. A block
     * type whose view needs its script to re-run answers `hotReload: false`, and
     * only then does the whole frame reload.
     */
    async _refreshBlockPreview(blockId) {
        if (this._previewHidden()) return;

        const doc = this._previewDocument();
        if (!doc) return;

        const url = this._url(blockId, 'cbRenderUrl');
        if (!url) return;

        let payload;
        try {
            const response = await fetch(url, { headers: { Accept: 'application/json' } });
            payload = await response.json();
        } catch {
            return;
        }

        if (!payload?.hotReload || !payload.html) {
            this.preview.contentWindow.location.reload();
            return;
        }

        const current = doc.querySelector(`[data-cb-block-id="${blockId}"]`);
        if (!current) return;

        const template = doc.createElement('template');
        template.innerHTML = payload.html.trim();
        const replacement = template.content.firstElementChild;

        if (replacement) {
            replacement.classList.add('cb-wb-focus');
            current.replaceWith(replacement);
        }
    }

    togglePreview() {
        const hidden = this.root.classList.toggle('cb-wb--no-preview');

        const toggle = this.root.querySelector('[data-target="previewToggle"]');
        toggle?.setAttribute('aria-pressed', String(!hidden));

        const label = this.root.querySelector('[data-target="previewToggleLabel"]');
        if (label) label.textContent = hidden ? label.dataset.show : label.dataset.hide;

        try {
            window.localStorage.setItem('cb-i18n.preview-hidden', hidden ? '1' : '0');
        } catch {
            // Private browsing: the preference simply does not persist.
        }
    }

    _previewHidden() {
        return this.root.classList.contains('cb-wb--no-preview');
    }

    _previewDocument() {
        try {
            return this.preview?.contentDocument ?? null;
        } catch {
            // Cross-origin: the host resolved the area to another host, so
            // scroll-sync and inline swap are simply unavailable.
            return null;
        }
    }

    /**
     * Injects the one style the preview needs of its own: the ring marking the
     * block being edited.
     *
     * The builder's toolbars and click-to-edit are **not** hidden from here —
     * the frame is loaded with `cb_chrome=0`, so the core never renders them.
     * Suppressing chrome in CSS was the earlier approach and it was the wrong
     * one: the overlay script still ran, still bound hover handlers, and still
     * posted messages at a window with no builder listening.
     */
    _dressPreview() {
        const doc = this._previewDocument();
        if (!doc) return;

        const style = doc.createElement('style');
        style.textContent = `
            .cb-wb-focus {
                outline: 2px solid #0e7490 !important;
                outline-offset: 3px;
                border-radius: 3px;
                transition: outline-color .2s ease;
            }
        `;
        doc.head?.appendChild(style);
    }

    // ---- list state -------------------------------------------------------

    _applyFilter(button) {
        this.filter = button.dataset.filterValue;

        this.root.querySelectorAll('[data-act="filter"]').forEach((el) => {
            el.classList.toggle('is-active', el === button);
        });

        this.root.querySelectorAll('[data-target="row"]').forEach((row) => {
            const show = this.filter === 'all' || row.dataset.status === this.filter;
            row.hidden = !show;

            // Hiding must remove the row from the tab order too, or "translate
            // the 19 missing fields" still tabs through the other 38.
            row.querySelectorAll('input, textarea, button').forEach((el) => {
                if (show) el.removeAttribute('tabindex');
                else el.setAttribute('tabindex', '-1');
            });
        });

        this.root.querySelectorAll('[data-cb-block]').forEach((block) => {
            block.hidden = !block.querySelector('[data-target="row"]:not([hidden])');
        });
    }

    /** Repaints a block's rows and the page counters from a server response. */
    _applyBlockState(block) {
        if (!block) return;

        for (const field of block.fields ?? []) {
            const row = this.root.querySelector(
                `[data-target="row"][data-block="${block.blockId}"][data-path="${CSS.escape(field.path)}"]`,
            );
            if (!row) continue;

            row.classList.remove('cb-wb__row--missing', 'cb-wb__row--translated', 'cb-wb__row--outdated', 'is-dirty');
            row.classList.add(`cb-wb__row--${field.status}`);
            row.dataset.status = field.status;

            const input = row.querySelector('[data-target="input"]');
            if (input && document.activeElement !== input) input.value = field.value ?? '';

            const stale = row.querySelector('.cb-wb__stale');
            if (stale) stale.hidden = field.status !== 'outdated';
        }

        this._recount();
        this._toast(this._message('saved'));
    }

    /**
     * Recomputes the counters from the DOM.
     *
     * From the rows rather than from a server total on purpose: the bar and the
     * list are then the same number by construction, and cannot disagree the way
     * they would if each had its own source.
     */
    _recount() {
        const rows = [...this.root.querySelectorAll('[data-target="row"]')];
        const count = (status) => rows.filter((r) => r.dataset.status === status).length;

        const translated = count('translated');
        const outdated = count('outdated');
        const missing = count('missing');
        const total = rows.length;
        const percent = total === 0 ? 100 : Math.round((translated / total) * 100);

        this._set('countTranslated', translated);
        this._set('countOutdated', outdated);
        this._set('countMissing', missing);
        this._set('percent', `${percent}%`);

        const fill = this.root.querySelector('[data-target="meterFill"]');
        if (fill) fill.style.width = `${percent}%`;
    }

    _set(target, value) {
        const el = this.root.querySelector(`[data-target="${target}"]`);
        if (el) el.textContent = String(value);
    }

    _markDirty(row) {
        row.classList.add('is-dirty');
    }

    // ---- plumbing ---------------------------------------------------------

    async _post(url, body, failureKey = 'save_failed') {
        // A missing URL means the row's block left the list (or the markup
        // changed): report it like any other failed save rather than throwing
        // a TypeError into an event handler nobody is watching.
        if (!url) {
            this._toast(this._message(failureKey), true);
            return null;
        }

        try {
            const response = await fetch(url, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': this.csrf },
                body: JSON.stringify(body),
            });

            if (!response.ok) {
                this._toast(this._message(failureKey), true);
                return null;
            }

            return await response.json();
        } catch {
            this._toast(this._message(failureKey), true);
            return null;
        }
    }

    _message(key) {
        return this.root.dataset[`i18n${key.replace(/(^|_)([a-z])/g, (_, __, c) => c.toUpperCase())}`] ?? key;
    }

    _toast(text, isError = false) {
        if (!this.toast) return;

        this.toast.textContent = text;
        this.toast.classList.toggle('cb-wb__toast--error', isError);
        this.toast.hidden = false;

        window.clearTimeout(this._toastTimer);
        this._toastTimer = window.setTimeout(() => {
            this.toast.hidden = true;
        }, isError ? 6000 : 1800);
    }

    _restorePreferences() {
        let hidden = false;
        try {
            hidden = window.localStorage.getItem('cb-i18n.preview-hidden') === '1';
        } catch {
            hidden = false;
        }

        if (hidden) this.togglePreview();
    }
}

export function start(root = document.querySelector('[data-cb-i18n-workbench]')) {
    return root ? new Workbench(root) : null;
}

if (typeof document !== 'undefined') {
    const boot = () => start();

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
    else boot();
}

export { Workbench };
