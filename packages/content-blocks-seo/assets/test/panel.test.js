import { describe, it, expect, beforeEach, vi } from 'vitest';
import { SeoPanel, onBuilderAction, ACTION_KEY } from '../panel.js';
import { audit } from '../audit.js';

const STRINGS = {
    running: 'Checking…',
    failed: 'Could not read',
    summary_none: 'No issue',
    errors_one: '{count} error',
    errors_other: '{count} errors',
    warnings_one: '{count} warning',
    warnings_other: '{count} warnings',
    scope_page: 'Page template',
    scope_area: 'This area',
    outline_untitled: '(empty)',
    check_image_alt_missing: 'No alt',
    hint_image_alt_missing: 'Describe it',
    check_heading_h1_missing: 'No h1',
    hint_heading_h1_missing: 'Add one',
    check_heading_level_skipped: 'h{from} then h{to}',
    hint_heading_level_skipped: 'Use h{next}',
};

function mount() {
    document.body.innerHTML = `
        <div class="cb-shell" data-cb-csrf-token="t">
            <aside class="cb-seo" data-cb-seo hidden
                   data-preview-url="/page/1?cb_preview=1"
                   data-locale="en"
                   data-options='{"paragraphMaxWords":150}'
                   data-i18n='${JSON.stringify(STRINGS)}'>
                <button data-cb-seo-rerun></button>
                <button data-cb-seo-close></button>
                <p data-cb-seo-status></p>
                <ul data-cb-seo-findings></ul>
                <ol data-cb-seo-outline></ol>
            </aside>
        </div>`;

    return {
        shell: document.querySelector('.cb-shell'),
        root: document.querySelector('[data-cb-seo]'),
    };
}

function html(body) {
    return `<!doctype html><body>${body}</body>`;
}

function respond(body, init = {}) {
    return Promise.resolve({ ok: true, redirected: false, status: 200, text: () => Promise.resolve(body), ...init });
}

const AREA = '<div class="cb-content-area" data-cb-preview="1">'
    + '<div data-cb-block-id="12"><img src="/uploads/hero.png"></div>'
    + '<div data-cb-block-id="13"><h2>A</h2><h4>B</h4></div></div>';

describe('SeoPanel', () => {
    let shell, root, fetch;

    beforeEach(() => {
        ({ shell, root } = mount());
        fetch = vi.fn(() => respond(html(`<h1>T</h1>${AREA}`)));
    });

    it('audits the draft page without the builder chrome', async () => {
        const panel = new SeoPanel(root, { fetch, audit });
        await panel.open();

        const url = new URL(fetch.mock.calls[0][0]);
        expect(url.pathname).toBe('/page/1');
        expect(url.searchParams.get('cb_preview')).toBe('1');
        expect(url.searchParams.get('cb_chrome')).toBe('0');
        expect(fetch.mock.calls[0][1]).toMatchObject({ credentials: 'same-origin', cache: 'no-store' });
        expect(root.hidden).toBe(false);
    });

    it('lists errors first, says the count, and fills placeholders', async () => {
        const panel = new SeoPanel(root, { fetch, audit });
        await panel.open();

        const rows = [...root.querySelectorAll('.cb-seo__finding')];
        expect(rows.map((r) => r.dataset.check)).toEqual(['image_alt_missing', 'heading_level_skipped']);
        expect(rows[1].querySelector('.cb-seo__message').textContent).toBe('h2 then h4');
        expect(rows[1].querySelector('.cb-seo__hint').textContent).toBe('Use h3');
        expect(rows[0].querySelector('.cb-seo__where').textContent).toBe('hero.png');
        expect(root.querySelector('[data-cb-seo-status]').textContent).toBe('1 error · 1 warning');
    });

    it('a click on a finding asks the builder to select its block', async () => {
        const panel = new SeoPanel(root, { fetch, audit });
        await panel.open();
        const focused = vi.fn();
        shell.addEventListener('cb:focus', (event) => focused(event.detail));

        root.querySelector('[data-check="image_alt_missing"]').click();

        expect(focused).toHaveBeenCalledWith({ kind: 'block', id: 12 });
    });

    it('a page finding says where it lives and is not a button', async () => {
        fetch = vi.fn(() => respond(html(AREA)));
        const panel = new SeoPanel(root, { fetch, audit });
        await panel.open();

        const row = root.querySelector('[data-check="heading_h1_missing"]');
        expect(row.tagName).toBe('DIV');
        expect(row.querySelector('.cb-seo__where').textContent).toBe('Page template');
    });

    it('draws the outline, indented by level', async () => {
        const panel = new SeoPanel(root, { fetch, audit });
        await panel.open();

        const headings = [...root.querySelectorAll('.cb-seo__heading')];
        expect(headings.map((h) => h.textContent)).toEqual(['h1T', 'h2A', 'h4B']);
        expect(headings[2].style.getPropertyValue('--cb-seo-depth')).toBe('3');
    });

    it('says so when the page is clean', async () => {
        fetch = vi.fn(() => respond(html('<h1>T</h1><div class="cb-content-area" data-cb-preview="1"></div>')));
        const panel = new SeoPanel(root, { fetch, audit });
        await panel.open();

        expect(root.querySelector('[data-cb-seo-status]').textContent).toBe('No issue');
    });

    it('refuses to audit a login page a redirect brought back', async () => {
        fetch = vi.fn(() => respond(html('<h1>Log in</h1>'), { redirected: true }));
        const panel = new SeoPanel(root, { fetch, audit });
        await panel.open();

        expect(root.querySelector('[data-cb-seo-status]').textContent).toBe('Could not read');
        expect(root.querySelectorAll('.cb-seo__finding')).toHaveLength(0);
    });

    it('says the failure when the request fails', async () => {
        fetch = vi.fn(() => Promise.reject(new TypeError('offline')));
        const panel = new SeoPanel(root, { fetch, audit });
        await panel.open();

        expect(root.querySelector('[data-cb-seo-status]').textContent).toBe('Could not read');
    });

    it('re-audits once after a burst of saves, only while open', async () => {
        vi.useFakeTimers();
        try {
            const panel = new SeoPanel(root, { fetch, audit, delay: 100 });
            await panel.open();
            fetch.mockClear();

            shell.dispatchEvent(new CustomEvent('cb:block:saved'));
            shell.dispatchEvent(new CustomEvent('cb:block:saved'));
            shell.dispatchEvent(new CustomEvent('cb:ready'));
            await vi.advanceTimersByTimeAsync(150);
            expect(fetch).toHaveBeenCalledTimes(1);

            panel.close();
            shell.dispatchEvent(new CustomEvent('cb:section:saved'));
            await vi.advanceTimersByTimeAsync(150);
            expect(fetch).toHaveBeenCalledTimes(1);
        } finally {
            vi.useRealTimers();
        }
    });

    it('drops an audit overtaken by a newer one', async () => {
        let release;
        const slow = new Promise((resolve) => { release = resolve; });
        fetch = vi.fn()
            .mockImplementationOnce(() => slow)
            .mockImplementation(() => respond(html('<h1>T</h1>')));
        const panel = new SeoPanel(root, { fetch, audit });

        const first = panel.open();
        await panel.refresh();
        release({ ok: true, redirected: false, text: () => Promise.resolve(html(AREA)) });
        await first;

        expect(root.querySelector('[data-cb-seo-status]').textContent).toBe('No issue');
    });

    it('uses the plural rules of the editor\'s language', async () => {
        root.dataset.locale = 'fr';
        root.dataset.i18n = JSON.stringify({ ...STRINGS, errors_one: '{count} erreur', errors_other: '{count} erreurs' });
        const panel = new SeoPanel(root, { fetch, audit });

        expect(panel.count('errors', 1)).toBe('1 erreur');
        expect(panel.count('errors', 2)).toBe('2 erreurs');
        expect(panel.count('errors', 0)).toBe('');
    });
});

describe('the Actions menu entry', () => {
    it('opens the panel of the shell it was clicked in, and closes it again', () => {
        const { shell, root } = mount();
        window.fetch = vi.fn(() => respond(html('<h1>T</h1>')));
        const button = document.createElement('button');
        shell.append(button);

        onBuilderAction({ detail: { key: ACTION_KEY }, target: button });
        expect(root.hidden).toBe(false);

        onBuilderAction({ detail: { key: ACTION_KEY }, target: button });
        expect(root.hidden).toBe(true);
    });

    it('ignores another action', () => {
        const { shell, root } = mount();

        onBuilderAction({ detail: { key: 'something-else' }, target: shell });

        expect(root.hidden).toBe(true);
    });
});
