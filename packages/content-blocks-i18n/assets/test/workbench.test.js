import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { Workbench, start } from '../workbench.js';

/**
 * Unit tests for the translation workbench module.
 *
 * The workbench is deliberately not a Stimulus controller (see AssetController
 * for why), so it is driven the plain way: build the markup the Twig template
 * emits, hand the root to `new Workbench(...)`, and assert on what it sends and
 * what it repaints. `fetch` is stubbed — the endpoints have their own PHP tests;
 * what matters here is the payload shape, which is where the design's load-
 * bearing distinctions live (`null` vs `""` above all).
 */

const SAVE_DEBOUNCE_MS = 600;

/** One row of the list, matching workbench.html.twig. */
function row({ block = '1', path = 'heading', status = 'missing', value = '', widget = 'input' } = {}) {
    const field = widget === 'textarea'
        ? `<textarea data-target="input">${value}</textarea>`
        : `<input type="text" data-target="input" value="${value}">`;

    return `
        <div class="cb-wb__row cb-wb__row--${status}" data-target="row"
             data-status="${status}" data-block="${block}" data-path="${path}">
            <p class="cb-wb__stale" ${status === 'outdated' ? '' : 'hidden'}></p>
            ${field}
            <button type="button" data-act="translateField"></button>
            <button type="button" data-act="approve"></button>
            <button type="button" data-act="reset"></button>
        </div>`;
}

/**
 * The endpoint URLs Twig generates per block. They carry a mount prefix here on
 * purpose: the package's routes can be mounted anywhere, and a test that used
 * the default paths would pass just as well against a script that rebuilt them
 * from a hardcoded string — which is the bug these attributes exist to prevent.
 */
const MOUNT = '/admin/translations';

function blockUrls(id) {
    return `data-cb-save-url="${MOUNT}/block/${id}/de"
            data-cb-approve-url="${MOUNT}/block/${id}/de/approve"
            data-cb-translate-url="${MOUNT}/block/${id}/de/translate"
            data-cb-render-url="/_content-blocks/block/${id}/render?locale=de"`;
}

function mount({ rows = [row()], providers = true } = {}) {
    const blocks = new Map();
    for (const markup of rows) {
        const id = /data-block="(\d+)"/.exec(markup)[1];
        blocks.set(id, (blocks.get(id) ?? '') + markup);
    }

    document.body.innerHTML = `
        <div class="cb-wb" data-cb-i18n-workbench
             data-cb-i18n-workbench-area-id-value="7"
             data-cb-i18n-workbench-locale-value="de"
             data-cb-translate-all-url="${MOUNT}/area/7/de/translate"
             data-cb-csrf-token="tok-123"
             data-i18n-saved="Enregistré"
             data-i18n-save-failed="Échec"
             data-i18n-translating="Traduction…"
             data-i18n-translate-failed="Traduction impossible"
             data-i18n-translate-all-confirm="Sûr ?">
            ${providers ? '<input type="hidden" data-target="provider" value="pseudo">' : ''}
            <button data-act="translateAll"></button>
            <button data-target="previewToggle" data-act="togglePreview" aria-pressed="true"></button>
            <span data-target="previewToggleLabel" data-hide="Masquer" data-show="Afficher">Masquer</span>

            <button data-act="filter" data-filter-value="all" class="is-active"></button>
            <button data-act="filter" data-filter-value="missing"></button>

            <span data-target="meterFill" style="width: 0%"></span>
            <span data-target="countTranslated"></span>
            <span data-target="countOutdated"></span>
            <span data-target="countMissing"></span>
            <span data-target="percent"></span>

            <section data-target="list">
                ${[...blocks].map(([id, inner]) =>
                    `<article data-cb-block="${id}" ${blockUrls(id)}>${inner}</article>`).join('')}
            </section>

            <aside data-target="previewPane"><iframe data-target="preview"></iframe></aside>
            <div data-target="toast" hidden></div>
        </div>`;

    return document.querySelector('[data-cb-i18n-workbench]');
}

/**
 * A fetch double that records calls and answers with a given JSON body.
 *
 * The render endpoint is answered separately by default: left to fall through,
 * a body with no `hotReload` sends the workbench down its full-frame-reload
 * path, which jsdom cannot perform and reports as an unimplemented navigation.
 */
function stubFetch(body = { block: { blockId: 1, fields: [] } }) {
    const calls = [];
    const answer = (url) => {
        if (typeof body === 'function') return body(url);

        return url.includes('/render')
            ? { hotReload: true, html: '<div data-cb-block-id="1">neu</div>' }
            : body;
    };

    global.fetch = vi.fn((url, options = {}) => {
        calls.push({ url, options, body: options.body ? JSON.parse(options.body) : null });

        return Promise.resolve({ ok: true, json: () => Promise.resolve(answer(url)) });
    });

    return calls;
}

/** Lets the debounce fire and the awaited fetch chain settle. */
async function settle() {
    await vi.advanceTimersByTimeAsync(SAVE_DEBOUNCE_MS + 10);
    await vi.advanceTimersByTimeAsync(0);
}

function type(root, value, index = 0) {
    const input = root.querySelectorAll('[data-target="input"]')[index];
    input.value = value;
    input.dispatchEvent(new Event('input', { bubbles: true }));

    return input;
}

beforeEach(() => {
    vi.useFakeTimers();
    window.localStorage.clear();

    // jsdom ships no `CSS` global; every browser the workbench runs in has had
    // CSS.escape for a decade. Field paths carry `[]` and `.`, so escaping them
    // before they go into a selector is not optional — this shim is only here
    // so the assertion below is about the workbench, not about jsdom.
    if (typeof globalThis.CSS === 'undefined') {
        globalThis.CSS = { escape: (value) => String(value).replace(/[^a-zA-Z0-9_-]/g, (c) => `\\${c}`) };
    }
});

afterEach(() => {
    vi.useRealTimers();
    vi.restoreAllMocks();
    delete global.fetch;
});

describe('saving', () => {
    it('batches a block\'s fields into one debounced request', async () => {
        // A translator tabbing through the four fields of a card must produce
        // one request, not four — the endpoint takes a batch for this reason.
        const root = mount({
            rows: [
                row({ block: '1', path: 'heading' }),
                row({ block: '1', path: 'body', widget: 'textarea' }),
            ],
        });
        const calls = stubFetch();
        new Workbench(root);

        type(root, 'Willkommen', 0);
        type(root, 'Wir liefern weltweit.', 1);
        await settle();

        const saves = calls.filter((c) => c.options.method === 'POST');
        expect(saves).toHaveLength(1);
        expect(saves[0].url).toBe(`${MOUNT}/block/1/de`);
        expect(saves[0].body.values).toEqual({
            heading: 'Willkommen',
            body: 'Wir liefern weltweit.',
        });
        expect(saves[0].options.headers['X-CSRF-Token']).toBe('tok-123');
    });

    it('keeps separate blocks in separate requests', async () => {
        const root = mount({
            rows: [row({ block: '1', path: 'heading' }), row({ block: '2', path: 'heading' })],
        });
        const calls = stubFetch();
        new Workbench(root);

        type(root, 'Eins', 0);
        type(root, 'Zwei', 1);
        await settle();

        const saves = calls.filter((c) => c.options.method === 'POST');
        expect(saves.map((c) => c.url)).toEqual([
            `${MOUNT}/block/1/de`,
            `${MOUNT}/block/2/de`,
        ]);
    });

    /**
     * The distinction the whole storage design rests on: `""` is a deliberate
     * blank ("this card has no subtitle in German"), `null` removes the
     * translation so the source shows through. Collapsing them would make the
     * first impossible to express.
     */
    it('reset sends null, not an empty string, and does not wait for the debounce', async () => {
        const root = mount({ rows: [row({ value: 'Willkommen', status: 'translated' })] });
        const calls = stubFetch();
        new Workbench(root);

        root.querySelector('[data-act="reset"]').click();
        await vi.advanceTimersByTimeAsync(0);

        const saves = calls.filter((c) => c.options.method === 'POST');
        expect(saves).toHaveLength(1);
        expect(saves[0].body.values).toEqual({ heading: null });
        expect(root.querySelector('[data-target="input"]').value).toBe('');
    });

    it('a typed value that is emptied is stored as a blank, not as a removal', async () => {
        const root = mount({ rows: [row({ value: 'Willkommen', status: 'translated' })] });
        const calls = stubFetch();
        new Workbench(root);

        type(root, '');
        await settle();

        expect(calls.filter((c) => c.options.method === 'POST')[0].body.values).toEqual({ heading: '' });
    });

    /**
     * A pending debounce must not be lost to a navigation: the edit is already
     * on screen and the editor considers it saved. `keepalive` is what lets the
     * request outlive the page.
     */
    it('flushes pending edits on unload with keepalive', async () => {
        const root = mount();
        const calls = stubFetch();
        new Workbench(root);

        type(root, 'Willkommen');
        window.dispatchEvent(new Event('beforeunload'));

        const saves = calls.filter((c) => c.options.method === 'POST');
        expect(saves).toHaveLength(1);
        expect(saves[0].options.keepalive).toBe(true);
        expect(saves[0].body.values).toEqual({ heading: 'Willkommen' });
    });

    it('reports a failed save and leaves the row alone', async () => {
        const root = mount();
        global.fetch = vi.fn(() => Promise.resolve({ ok: false, json: () => Promise.resolve({}) }));
        new Workbench(root);

        type(root, 'Willkommen');
        await settle();

        const toast = root.querySelector('[data-target="toast"]');
        expect(toast.hidden).toBe(false);
        expect(toast.textContent).toBe('Échec');
        expect(toast.classList.contains('cb-wb__toast--error')).toBe(true);
        // Still missing: nothing was confirmed, so nothing is repainted.
        expect(root.querySelector('[data-target="row"]').dataset.status).toBe('missing');
    });

    it('survives a network error the same way', async () => {
        const root = mount();
        global.fetch = vi.fn(() => Promise.reject(new Error('offline')));
        new Workbench(root);

        type(root, 'Willkommen');
        await settle();

        expect(root.querySelector('[data-target="toast"]').textContent).toBe('Échec');
    });
});

describe('repainting from the server response', () => {
    it('applies the returned status and recounts the page from the rows', async () => {
        const root = mount({
            rows: [
                row({ block: '1', path: 'heading' }),
                row({ block: '1', path: 'body' }),
            ],
        });
        stubFetch({
            block: {
                blockId: 1,
                fields: [
                    { path: 'heading', status: 'translated', value: 'Willkommen' },
                    { path: 'body', status: 'outdated', value: 'Alt' },
                ],
            },
        });
        new Workbench(root);

        type(root, 'Willkommen');
        await settle();

        const [heading, body] = root.querySelectorAll('[data-target="row"]');
        expect(heading.dataset.status).toBe('translated');
        expect(heading.classList.contains('cb-wb__row--translated')).toBe(true);
        expect(heading.classList.contains('is-dirty')).toBe(false);

        expect(body.dataset.status).toBe('outdated');
        // The staleness note is the only thing that tells a reviewer *why* a
        // filled-in row still needs attention.
        expect(body.querySelector('.cb-wb__stale').hidden).toBe(false);

        // Counters come from the rows, so the bar and the list cannot disagree.
        expect(root.querySelector('[data-target="countTranslated"]').textContent).toBe('1');
        expect(root.querySelector('[data-target="countOutdated"]').textContent).toBe('1');
        expect(root.querySelector('[data-target="countMissing"]').textContent).toBe('0');
        expect(root.querySelector('[data-target="percent"]').textContent).toBe('50%');
        expect(root.querySelector('[data-target="meterFill"]').style.width).toBe('50%');
    });

    /**
     * A response landing while the translator is still typing must not yank the
     * text out from under them — the debounce means a save is always in flight
     * behind the cursor.
     */
    it('does not overwrite the field the editor is focused in', async () => {
        const root = mount();
        stubFetch({
            block: { blockId: 1, fields: [{ path: 'heading', status: 'translated', value: 'Server-Wert' }] },
        });
        new Workbench(root);

        const input = type(root, 'Willkommen');
        input.focus();
        await settle();

        expect(input.value).toBe('Willkommen');
    });

    it('approve re-stamps the row without sending a value', async () => {
        const root = mount({ rows: [row({ status: 'outdated', value: 'Alt' })] });
        const calls = stubFetch({
            block: { blockId: 1, fields: [{ path: 'heading', status: 'translated', value: 'Alt' }] },
        });
        new Workbench(root);

        root.querySelector('[data-act="approve"]').click();
        await vi.advanceTimersByTimeAsync(0);

        expect(calls[0].url).toBe(`${MOUNT}/block/1/de/approve`);
        expect(calls[0].body).toEqual({ paths: ['heading'] });
        expect(root.querySelector('[data-target="row"]').dataset.status).toBe('translated');
        expect(root.querySelector('.cb-wb__stale').hidden).toBe(true);
    });
});

describe('machine translation', () => {
    it('translates one field through the selected provider', async () => {
        const root = mount({ rows: [row({ path: 'heading' })] });
        const calls = stubFetch({
            block: { blockId: 1, fields: [{ path: 'heading', status: 'translated', value: 'Willkommen' }] },
            result: { failed: {} },
        });
        new Workbench(root);

        root.querySelector('[data-act="translateField"]').click();
        await vi.advanceTimersByTimeAsync(0);

        expect(calls[0].url).toBe(`${MOUNT}/block/1/de/translate`);
        expect(calls[0].body).toEqual({ paths: ['heading'], provider: 'pseudo' });
        expect(root.querySelector('[data-target="row"]').dataset.status).toBe('translated');
    });

    /**
     * A provider can fail per field while the request itself succeeds (quota,
     * unsupported pair). Reporting it is what separates "nothing happened" from
     * "the engine said no".
     */
    it('surfaces a per-field provider failure', async () => {
        const root = mount();
        stubFetch({
            block: { blockId: 1, fields: [] },
            result: { failed: { heading: 'quota exceeded' } },
        });
        new Workbench(root);

        root.querySelector('[data-act="translateField"]').click();
        await vi.advanceTimersByTimeAsync(0);

        const toast = root.querySelector('[data-target="toast"]');
        expect(toast.textContent).toBe('Traduction impossible — quota exceeded');
        expect(toast.classList.contains('cb-wb__toast--error')).toBe(true);
    });

    it('asks before translating the whole page, and does nothing if refused', async () => {
        const root = mount();
        const calls = stubFetch();
        vi.spyOn(window, 'confirm').mockReturnValue(false);
        new Workbench(root);

        root.querySelector('[data-act="translateAll"]').click();
        await vi.advanceTimersByTimeAsync(0);

        expect(window.confirm).toHaveBeenCalledWith('Sûr ?');
        expect(calls).toHaveLength(0);
    });
});

describe('the list', () => {
    /**
     * Hiding a row must take it out of the tab order too, or "translate the
     * missing fields" still tabs through all the others.
     */
    it('filtering hides rows and removes them from the tab order', () => {
        const root = mount({
            rows: [
                row({ block: '1', path: 'heading', status: 'translated' }),
                row({ block: '2', path: 'body', status: 'missing' }),
            ],
        });
        stubFetch();
        new Workbench(root);

        root.querySelector('[data-act="filter"][data-filter-value="missing"]').click();

        const [translated, missing] = root.querySelectorAll('[data-target="row"]');
        expect(translated.hidden).toBe(true);
        expect(translated.querySelector('[data-target="input"]').getAttribute('tabindex')).toBe('-1');
        expect(missing.hidden).toBe(false);
        expect(missing.querySelector('[data-target="input"]').hasAttribute('tabindex')).toBe(false);

        // A block whose every row is filtered out goes with them, rather than
        // leaving a bare heading behind.
        const [blockOne, blockTwo] = root.querySelectorAll('[data-cb-block]');
        expect(blockOne.hidden).toBe(true);
        expect(blockTwo.hidden).toBe(false);

        root.querySelector('[data-act="filter"][data-filter-value="all"]').click();
        expect(translated.hidden).toBe(false);
        expect(blockOne.hidden).toBe(false);
    });
});

/**
 * Not covered here: the `hotReload: false` fallback, where the whole frame
 * reloads. jsdom marks `location.reload` unforgeable, so it can be neither
 * performed nor spied on — the branch is exercised by the e2e suite instead.
 */
describe('the preview pane', () => {
    it('re-renders exactly one block, in the locale being translated', async () => {
        const root = mount();
        const calls = stubFetch((url) => (url.includes('/render')
            ? { hotReload: true, html: '<div data-cb-block-id="1">neu</div>' }
            : { block: { blockId: 1, fields: [] } }));
        const workbench = new Workbench(root);

        const doc = workbench.preview.contentDocument;
        doc.body.innerHTML = '<div data-cb-block-id="1">alt</div>';

        type(root, 'Willkommen');
        await settle();

        const render = calls.find((c) => c.url.includes('/render'));
        expect(render.url).toBe('/_content-blocks/block/1/render?locale=de');
        expect(doc.querySelector('[data-cb-block-id="1"]').textContent).toBe('neu');
        // The frame itself is never reloaded, so scroll position and any JS
        // state in the preview survive the edit.
        expect(doc.querySelector('[data-cb-block-id="1"]').classList.contains('cb-wb-focus')).toBe(true);
    });

    it('does not fetch a preview it is not showing', async () => {
        const root = mount();
        const calls = stubFetch();
        new Workbench(root);

        root.querySelector('[data-act="togglePreview"]').click();
        type(root, 'Willkommen');
        await settle();

        expect(calls.some((c) => c.url.includes('/render'))).toBe(false);
    });

    it('remembers a hidden preview across page loads', () => {
        stubFetch();
        const first = mount();
        new Workbench(first);

        first.querySelector('[data-act="togglePreview"]').click();
        expect(first.classList.contains('cb-wb--no-preview')).toBe(true);
        expect(first.querySelector('[data-target="previewToggle"]').getAttribute('aria-pressed')).toBe('false');
        expect(first.querySelector('[data-target="previewToggleLabel"]').textContent).toBe('Afficher');

        const second = mount();
        new Workbench(second);
        expect(second.classList.contains('cb-wb--no-preview')).toBe(true);
    });

    it('focusing a field highlights its block in the preview', () => {
        const root = mount();
        stubFetch();
        const workbench = new Workbench(root);

        const doc = workbench.preview.contentDocument;
        doc.body.innerHTML = '<div data-cb-block-id="1">alt</div>';
        const target = doc.querySelector('[data-cb-block-id="1"]');
        target.scrollIntoView = vi.fn();

        root.querySelector('[data-target="input"]').dispatchEvent(new Event('focusin', { bubbles: true }));

        expect(target.classList.contains('cb-wb-focus')).toBe(true);
        expect(target.scrollIntoView).toHaveBeenCalled();
    });
});

describe('booting', () => {
    it('start() returns null when the page carries no workbench', () => {
        document.body.innerHTML = '<p>some other page</p>';

        expect(start()).toBe(null);
    });

    it('start() picks up the root by its data attribute', () => {
        stubFetch();
        mount();

        expect(start()).toBeInstanceOf(Workbench);
    });
});
