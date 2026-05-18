import { describe, it, expect, beforeEach, vi } from 'vitest';
import Controller from '../controllers/cb-builder_controller.js';

/**
 * Unit tests for the replace-content picker methods on cb-builder. The
 * Stimulus runtime isn't booted — we instantiate the class directly and
 * stub the framework-supplied targets/values.
 */

function setupController(options = {}) {
    document.body.innerHTML = `
        <div data-controller="cb-builder">
            <button class="cb-shell__replace" aria-expanded="false"></button>
            <iframe></iframe>
            <div class="cb-replace-picker" hidden></div>
            <input data-cb-builder-target="replacePickerSearch" />
            <ul class="cb-replace-picker__list"></ul>
            <p class="cb-replace-picker__status"></p>
        </div>
    `;
    const element = document.querySelector('[data-controller="cb-builder"]');
    const picker = element.querySelector('.cb-replace-picker');
    const search = element.querySelector('[data-cb-builder-target="replacePickerSearch"]');
    const list = element.querySelector('.cb-replace-picker__list');
    const status = element.querySelector('.cb-replace-picker__status');
    const iframe = element.querySelector('iframe');

    const controller = new Controller();
    Object.defineProperty(controller, 'element', { value: element });
    Object.defineProperty(controller, 'hasReplacePickerTarget', { value: true });
    Object.defineProperty(controller, 'replacePickerTarget', { value: picker });
    Object.defineProperty(controller, 'hasReplacePickerSearchTarget', { value: true });
    Object.defineProperty(controller, 'replacePickerSearchTarget', { value: search });
    Object.defineProperty(controller, 'hasReplacePickerListTarget', { value: true });
    Object.defineProperty(controller, 'replacePickerListTarget', { value: list });
    Object.defineProperty(controller, 'hasReplacePickerStatusTarget', { value: true });
    Object.defineProperty(controller, 'replacePickerStatusTarget', { value: status });
    Object.defineProperty(controller, 'hasIframeTarget', { value: true });
    Object.defineProperty(controller, 'iframeTarget', { value: iframe });
    Object.defineProperty(controller, 'areaIdValue', { value: options.areaId ?? 42 });

    element.dataset.cbCsrfToken = 'tok-123';

    return { controller, element, picker, search, list, status };
}

describe('cb-builder replace picker: open / close', () => {
    let controller, picker, search;

    beforeEach(() => {
        ({ controller, picker, search } = setupController());
        vi.spyOn(console, 'log').mockImplementation(() => {});
    });

    it('openReplacePicker unhides the panel and focuses the search field', async () => {
        global.fetch = vi.fn(() => Promise.resolve({
            ok: true,
            json: () => Promise.resolve({ items: [], hasMore: false }),
        }));

        await controller.openReplacePicker({ preventDefault: () => {} });

        expect(picker.hidden).toBe(false);
        expect(document.activeElement).toBe(search);
        expect(controller.element.querySelector('.cb-shell__replace').getAttribute('aria-expanded')).toBe('true');
    });

    it('openReplacePicker only fetches candidates once across reopens', async () => {
        global.fetch = vi.fn(() => Promise.resolve({
            ok: true,
            json: () => Promise.resolve({ items: [], hasMore: false }),
        }));

        await controller.openReplacePicker();
        controller.closeReplacePicker();
        await controller.openReplacePicker();

        expect(global.fetch).toHaveBeenCalledTimes(1);
    });

    it('closeReplacePicker hides the panel and flips aria-expanded back', () => {
        picker.hidden = false;
        controller.element.querySelector('.cb-shell__replace').setAttribute('aria-expanded', 'true');

        controller.closeReplacePicker({ preventDefault: () => {} });

        expect(picker.hidden).toBe(true);
        expect(controller.element.querySelector('.cb-shell__replace').getAttribute('aria-expanded')).toBe('false');
    });
});

describe('cb-builder replace picker: list rendering', () => {
    let controller, list, status;

    beforeEach(() => {
        ({ controller, list, status } = setupController({ areaId: 99 }));
        vi.spyOn(console, 'log').mockImplementation(() => {});
        vi.spyOn(console, 'error').mockImplementation(() => {});
    });

    it('renders one item per candidate with the provider label', async () => {
        global.fetch = vi.fn(() => Promise.resolve({
            ok: true,
            json: () => Promise.resolve({
                items: [
                    { id: 1, label: 'Homepage — 2026-05-18' },
                    { id: 2, label: 'About — 2026-05-17' },
                ],
                hasMore: false,
            }),
        }));

        await controller.openReplacePicker();

        const items = list.querySelectorAll('.cb-replace-picker__item-btn');
        expect(items).toHaveLength(2);
        expect(items[0].textContent).toBe('Homepage — 2026-05-18');
        expect(items[0].dataset.cbReplaceSourceId).toBe('1');
        expect(status.textContent).toBe('');
    });

    it('fetches the candidates endpoint without ?q when filter is empty', async () => {
        global.fetch = vi.fn(() => Promise.resolve({
            ok: true,
            json: () => Promise.resolve({ items: [], hasMore: false }),
        }));

        await controller.openReplacePicker();

        expect(global.fetch).toHaveBeenCalledWith(
            '/_content-blocks/area/99/replace-candidates',
            expect.any(Object),
        );
    });

    it('appends ?q= when the search input has a value', async () => {
        global.fetch = vi.fn(() => Promise.resolve({
            ok: true,
            json: () => Promise.resolve({ items: [], hasMore: false }),
        }));

        await controller._loadReplaceCandidates('hello world');

        expect(global.fetch).toHaveBeenCalledWith(
            '/_content-blocks/area/99/replace-candidates?q=hello+world',
            expect.any(Object),
        );
    });

    it('shows the unfiltered "empty" message when there are zero candidates and no filter', async () => {
        global.fetch = vi.fn(() => Promise.resolve({
            ok: true,
            json: () => Promise.resolve({ items: [], hasMore: false }),
        }));

        await controller._loadReplaceCandidates('');

        expect(list.children).toHaveLength(0);
        expect(status.textContent).toMatch(/No content available|Aucun/i);
    });

    it('shows the filtered "no results" message when there are zero candidates with a filter', async () => {
        global.fetch = vi.fn(() => Promise.resolve({
            ok: true,
            json: () => Promise.resolve({ items: [], hasMore: false }),
        }));

        await controller._loadReplaceCandidates('zzz');

        expect(status.textContent).toMatch(/No results|Aucun r/i);
    });

    it('shows the error message when fetch fails', async () => {
        global.fetch = vi.fn(() => Promise.resolve({ ok: false, status: 500 }));

        await controller._loadReplaceCandidates('');

        expect(status.textContent).toMatch(/Failed|Impossible/i);
        expect(list.children).toHaveLength(0);
    });
});

describe('cb-builder replace picker: search debounce', () => {
    let controller;

    beforeEach(() => {
        ({ controller } = setupController({ areaId: 99 }));
        vi.spyOn(console, 'log').mockImplementation(() => {});
        vi.useFakeTimers();
    });

    it('coalesces rapid keystrokes into a single fetch fired after the debounce window', () => {
        const loadSpy = vi.spyOn(controller, '_loadReplaceCandidates').mockResolvedValue();

        controller.onReplacePickerSearch({ target: { value: 'a' } });
        controller.onReplacePickerSearch({ target: { value: 'ab' } });
        controller.onReplacePickerSearch({ target: { value: 'abc' } });
        expect(loadSpy).not.toHaveBeenCalled();

        vi.advanceTimersByTime(Controller.REPLACE_PICKER_DEBOUNCE_MS);
        expect(loadSpy).toHaveBeenCalledTimes(1);
        expect(loadSpy).toHaveBeenLastCalledWith('abc');
    });
});

describe('cb-builder replace picker: confirm + replace', () => {
    let controller;

    beforeEach(() => {
        ({ controller } = setupController({ areaId: 99 }));
        vi.spyOn(console, 'log').mockImplementation(() => {});
    });

    it('asks for native confirm before posting the replace-with request', async () => {
        const confirmSpy = vi.spyOn(window, 'confirm').mockReturnValue(false);
        const reqSpy = vi.spyOn(controller, '_jsonRequest').mockResolvedValue(null);

        await controller._confirmAndReplace({ id: 7, label: 'Other page' });

        expect(confirmSpy).toHaveBeenCalled();
        expect(reqSpy).not.toHaveBeenCalled();
    });

    it('posts to /area/{id}/replace-with/{sourceId} when confirm accepts, then closes and reloads', async () => {
        vi.spyOn(window, 'confirm').mockReturnValue(true);
        const reqSpy = vi.spyOn(controller, '_jsonRequest').mockResolvedValue({ hasUnpublishedChanges: true });
        const applySpy = vi.spyOn(controller, '_applyDraftState').mockImplementation(() => {});
        const reloadSpy = vi.spyOn(controller, 'reload').mockImplementation(() => {});

        await controller._confirmAndReplace({ id: 11, label: 'Source' });

        expect(reqSpy).toHaveBeenCalledWith('POST', '/_content-blocks/area/99/replace-with/11');
        expect(applySpy).toHaveBeenCalledWith(true);
        expect(reloadSpy).toHaveBeenCalled();
        // Picker is closed after a successful replace.
        const picker = controller.element.querySelector('.cb-replace-picker');
        expect(picker.hidden).toBe(true);
    });

    it('does not reload when the request fails', async () => {
        vi.spyOn(window, 'confirm').mockReturnValue(true);
        vi.spyOn(controller, '_jsonRequest').mockResolvedValue(null);
        const reloadSpy = vi.spyOn(controller, 'reload').mockImplementation(() => {});

        await controller._confirmAndReplace({ id: 11, label: 'Source' });

        expect(reloadSpy).not.toHaveBeenCalled();
    });

    it('invalidates the cached list after a successful replace so the next open re-fetches', async () => {
        vi.spyOn(window, 'confirm').mockReturnValue(true);
        vi.spyOn(controller, '_jsonRequest').mockResolvedValue({ hasUnpublishedChanges: true });
        vi.spyOn(controller, '_applyDraftState').mockImplementation(() => {});
        vi.spyOn(controller, 'reload').mockImplementation(() => {});

        controller._replacePickerLoaded = true;
        await controller._confirmAndReplace({ id: 11, label: 'Source' });

        expect(controller._replacePickerLoaded).toBe(false);
    });
});

describe('cb-builder replace picker: translation fallback', () => {
    it('reads the data-i18n-* attribute on the picker root when present', () => {
        const { controller, picker } = setupController();
        picker.setAttribute('data-i18n-cb-builder-replace-loading', 'Chargement…');

        expect(controller._t('cb.builder.replace.loading', 'Loading…')).toBe('Chargement…');
    });

    it('falls back to the English default when no data-i18n attribute is set', () => {
        const { controller } = setupController();

        expect(controller._t('cb.builder.replace.loading', 'Loading…')).toBe('Loading…');
    });
});
