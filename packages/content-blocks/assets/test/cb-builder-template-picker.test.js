import { describe, it, expect, beforeEach, vi } from 'vitest';
import Controller from '../controllers/cb-builder_controller.js';

/**
 * Unit tests for the section-template library on cb-builder. The Stimulus
 * runtime isn't booted — we instantiate the class directly and stub the
 * framework-supplied targets/values, mirroring the replace-picker suite.
 *
 * The library is no longer a modal: it renders inside the empty sidebar, so
 * "open" means "clear the selection and paint the list", and there is nothing
 * to close. The row-building and pagination logic is unchanged, which is why
 * those tests read the same as before.
 */

function setupController(options = {}) {
    document.body.innerHTML = `
        <div data-controller="cb-builder">
            <iframe></iframe>
            <div class="cb-sidebar-library"></div>
            <input data-cb-builder-target="templatePickerSearch" />
            <ul class="cb-template-picker__list"></ul>
            <p class="cb-template-picker__status"></p>
        </div>
    `;
    const element = document.querySelector('[data-controller="cb-builder"]');
    const picker = element.querySelector('.cb-sidebar-library');
    const search = element.querySelector('[data-cb-builder-target="templatePickerSearch"]');
    const list = element.querySelector('.cb-template-picker__list');
    const status = element.querySelector('.cb-template-picker__status');

    const controller = new Controller();
    Object.defineProperty(controller, 'element', { value: element });
    Object.defineProperty(controller, 'hasTemplatePickerTarget', { value: true });
    Object.defineProperty(controller, 'templatePickerTarget', { value: picker });
    Object.defineProperty(controller, 'hasTemplatePickerSearchTarget', { value: true });
    Object.defineProperty(controller, 'templatePickerSearchTarget', { value: search });
    Object.defineProperty(controller, 'hasTemplatePickerListTarget', { value: true });
    Object.defineProperty(controller, 'templatePickerListTarget', { value: list });
    Object.defineProperty(controller, 'hasTemplatePickerStatusTarget', { value: true });
    Object.defineProperty(controller, 'templatePickerStatusTarget', { value: status });
    Object.defineProperty(controller, 'areaIdValue', { value: options.areaId ?? 42 });

    element.dataset.cbCsrfToken = 'tok-123';
    // jsdom has no matchMedia; the library opener asks whether we're on mobile
    // so it can un-collapse the bottom sheet. Answer "desktop".
    window.matchMedia = vi.fn(() => ({ matches: false, addEventListener() {} }));

    return { controller, element, picker, search, list, status };
}

function okJson(body) {
    return Promise.resolve({ ok: true, json: () => Promise.resolve(body) });
}

describe('cb-builder template library: opening', () => {
    let controller, search, list;

    beforeEach(() => {
        ({ controller, search, list } = setupController());
        vi.spyOn(console, 'log').mockImplementation(() => {});
    });

    it('openTemplatePicker focuses the search field', async () => {
        global.fetch = vi.fn(() => okJson({ items: [], hasMore: false }));

        await controller.openTemplatePicker({ preventDefault: () => {} });

        expect(document.activeElement).toBe(search);
    });

    it('only fetches the list once across reopens', async () => {
        global.fetch = vi.fn(() => okJson({ items: [], hasMore: false }));

        await controller.openTemplatePicker();
        await controller.openTemplatePicker();

        expect(global.fetch).toHaveBeenCalledTimes(1);
    });

    /**
     * The sidebar is rebuilt from an HTML snapshot every time the selection is
     * cleared, so the painted rows are thrown away — but a click on empty
     * preview space must not cost a round trip.
     */
    it('repaints from cache when the sidebar is rebuilt, without refetching', async () => {
        global.fetch = vi.fn(() => okJson({
            items: [{ id: 1, name: 'Hero', insertable: true, canManage: false }],
            hasMore: false,
        }));

        await controller.openTemplatePicker();
        expect(list.querySelectorAll('.cb-template-picker__item-btn')).toHaveLength(1);

        list.innerHTML = '';
        await controller._showTemplates();

        expect(global.fetch).toHaveBeenCalledTimes(1);
        expect(list.querySelectorAll('.cb-template-picker__item-btn')).toHaveLength(1);
    });
});

describe('cb-builder template picker: list rendering', () => {
    let controller, list, status;

    beforeEach(() => {
        ({ controller, list, status } = setupController({ areaId: 99 }));
        vi.spyOn(console, 'log').mockImplementation(() => {});
        vi.spyOn(console, 'error').mockImplementation(() => {});
    });

    it('renders a clickable button per compatible template', async () => {
        global.fetch = vi.fn(() => okJson({
            items: [
                { id: 1, name: 'Hero', insertable: true, canManage: false },
                { id: 2, name: 'CTA', insertable: true, canManage: false },
            ],
            hasMore: false,
        }));

        await controller.openTemplatePicker();

        const btns = list.querySelectorAll('.cb-template-picker__item-btn');
        expect(btns).toHaveLength(2);
        expect(btns[0].textContent).toBe('Hero');
        expect(btns[0].disabled).toBe(false);
        expect(status.textContent).toBe('');
    });

    it('disables a template whose block types are all gone', async () => {
        global.fetch = vi.fn(() => okJson({
            items: [
                { id: 3, name: 'Legacy', insertable: false, skippedTypes: ['gallery', 'map'], canManage: false },
            ],
            hasMore: false,
        }));

        await controller.openTemplatePicker();

        const btn = list.querySelector('.cb-template-picker__item-btn');
        expect(btn.disabled).toBe(true);
        expect(btn.title).toMatch(/gallery, map/);
        expect(list.querySelector('.cb-template-picker__item--disabled')).not.toBeNull();
    });

    it('keeps a partially usable template clickable, warning which blocks go', async () => {
        // Some of its blocks are gone, not all: inserting is still useful, so
        // the editor is warned before the click instead of being blocked.
        global.fetch = vi.fn(() => okJson({
            items: [
                { id: 8, name: 'Mostly fine', insertable: true, skippedTypes: ['map'], canManage: false },
            ],
            hasMore: false,
        }));

        await controller.openTemplatePicker();

        const btn = list.querySelector('.cb-template-picker__item-btn');
        expect(btn.disabled).toBe(false);
        expect(btn.title).toMatch(/map/);
        expect(btn.title).toMatch(/1/);
        expect(list.querySelector('.cb-template-picker__item--partial')).not.toBeNull();
        expect(list.querySelector('.cb-template-picker__item--disabled')).toBeNull();
    });

    it('disables templates whose payload envelope this build cannot read', async () => {
        // A snapshot saved under an older payload structure. The insert would
        // 422, so the row is greyed out with its own explanation — "missing
        // block types" would be a lie here, the list is empty.
        global.fetch = vi.fn(() => okJson({
            items: [
                { id: 6, name: 'Ancient', insertable: false, skippedTypes: [], unreadableFormat: true, canManage: false },
            ],
            hasMore: false,
        }));

        await controller.openTemplatePicker();

        const btn = list.querySelector('.cb-template-picker__item-btn');
        expect(btn.disabled).toBe(true);
        expect(btn.title).toMatch(/incompatible version/i);
        expect(btn.title).not.toMatch(/block type/i);
    });

    it('disables a template from an older content generation, saying so', async () => {
        // Not the same problem as a missing block type or an unreadable
        // envelope: this one is on the host to migrate, so the message must
        // not blame a block.
        global.fetch = vi.fn(() => okJson({
            items: [
                { id: 9, name: 'Legacy gen', insertable: false, skippedTypes: [], staleVersion: true, canManage: false },
            ],
            hasMore: false,
        }));

        await controller.openTemplatePicker();

        const btn = list.querySelector('.cb-template-picker__item-btn');
        expect(btn.disabled).toBe(true);
        expect(btn.title).toMatch(/content schema/i);
        expect(btn.title).not.toMatch(/block type/i);
    });

    it('shows a delete button only when the template is manageable', async () => {
        global.fetch = vi.fn(() => okJson({
            items: [
                { id: 4, name: 'Owned', insertable: true, canManage: true },
                { id: 5, name: 'ReadOnly', insertable: true, canManage: false },
            ],
            hasMore: false,
        }));

        await controller.openTemplatePicker();

        const rows = list.querySelectorAll('.cb-template-picker__item');
        expect(rows[0].querySelector('.cb-template-picker__delete')).not.toBeNull();
        expect(rows[1].querySelector('.cb-template-picker__delete')).toBeNull();
    });

    it('renders a "load more" control when hasMore and pages through on click', async () => {
        global.fetch = vi.fn()
            .mockImplementationOnce(() => okJson({
                items: [{ id: 1, name: 'A', insertable: true, canManage: false }],
                hasMore: true,
                page: 0,
            }))
            .mockImplementationOnce(() => okJson({
                items: [{ id: 2, name: 'B', insertable: true, canManage: false }],
                hasMore: false,
                page: 1,
            }));

        await controller.openTemplatePicker();
        const more = list.querySelector('.cb-template-picker__more');
        expect(more).not.toBeNull();

        more.click();
        await Promise.resolve();
        await Promise.resolve();

        // Second page appended below the first, "more" button consumed.
        expect(global.fetch).toHaveBeenNthCalledWith(
            2,
            '/_content-blocks/area/99/section-templates?page=1',
            expect.any(Object),
        );
        expect(list.querySelectorAll('.cb-template-picker__item-btn')).toHaveLength(2);
        expect(list.querySelector('.cb-template-picker__more')).toBeNull();
    });

    it('builds the list URL with ?q= when filtering', async () => {
        global.fetch = vi.fn(() => okJson({ items: [], hasMore: false }));

        await controller._loadTemplates('hero', 0, false);

        expect(global.fetch).toHaveBeenCalledWith(
            '/_content-blocks/area/99/section-templates?q=hero',
            expect.any(Object),
        );
    });

    it('shows the empty message when there are no templates', async () => {
        global.fetch = vi.fn(() => okJson({ items: [], hasMore: false }));

        await controller._loadTemplates('', 0, false);

        expect(list.children).toHaveLength(0);
        expect(status.textContent).toMatch(/No saved templates|Aucun mod/i);
    });

    it('shows the error message when the fetch fails', async () => {
        global.fetch = vi.fn(() => Promise.resolve({ ok: false, status: 500 }));

        await controller._loadTemplates('', 0, false);

        expect(status.textContent).toMatch(/Failed|Impossible/i);
    });
});

describe('cb-builder template picker: search debounce', () => {
    let controller;

    beforeEach(() => {
        ({ controller } = setupController({ areaId: 99 }));
        vi.useFakeTimers();
    });

    it('coalesces keystrokes into a single reset-to-page-0 load', () => {
        const loadSpy = vi.spyOn(controller, '_loadTemplates').mockResolvedValue();

        controller.onTemplatePickerSearch({ target: { value: 'a' } });
        controller.onTemplatePickerSearch({ target: { value: 'ab' } });
        expect(loadSpy).not.toHaveBeenCalled();

        vi.advanceTimersByTime(Controller.REPLACE_PICKER_DEBOUNCE_MS);
        expect(loadSpy).toHaveBeenCalledTimes(1);
        expect(loadSpy).toHaveBeenLastCalledWith('ab', 0, false);
    });
});

describe('cb-builder template picker: insert', () => {
    let controller;

    beforeEach(() => {
        ({ controller } = setupController({ areaId: 99 }));
        vi.spyOn(console, 'log').mockImplementation(() => {});
    });

    it('posts to insert-template, applies draft, scrolls to and opens the new section', async () => {
        const reqSpy = vi.spyOn(controller, '_jsonRequest').mockResolvedValue({ sectionId: 55, unknownFields: [] });
        const afterSpy = vi.spyOn(controller, '_afterStructuralOp').mockImplementation(() => {});
        const mountSpy = vi.spyOn(controller, '_mountSectionSettings').mockImplementation(() => {});

        await controller._confirmInsert({ id: 7 });

        expect(reqSpy).toHaveBeenCalledWith('POST', '/_content-blocks/area/99/insert-template/7');
        expect(afterSpy).toHaveBeenCalled();
        expect(mountSpy).toHaveBeenCalledWith(55);
        // An inserted section lands at the end of the area, off screen on any
        // page longer than the viewport — the reload has to go find it rather
        // than restore where the editor happened to be.
        expect(controller._pendingScrollSectionId).toBe(55);
    });

    it('surfaces non-blocking field warnings but still inserts', async () => {
        vi.spyOn(controller, '_jsonRequest').mockResolvedValue({
            sectionId: 55,
            skippedBlockCount: 0,
            skippedBlockTypes: [],
            unknownFields: [{ blockType: 'title', unknownKeys: ['subtitle'] }],
        });
        const afterSpy = vi.spyOn(controller, '_afterStructuralOp').mockImplementation(() => {});
        vi.spyOn(controller, '_mountSectionSettings').mockImplementation(() => {});

        // A row can only be clicked from an open picker.
        controller.templatePickerTarget.hidden = false;

        await controller._confirmInsert({ id: 7 });

        expect(controller.templatePickerStatusTarget.textContent).toMatch(/title/);
        // The status line lives inside the picker: closing it on the way out
        // would blank the message before anyone could read it.
        expect(controller.templatePickerTarget.hidden).toBe(false);
        expect(afterSpy).toHaveBeenCalled();
    });

    it('reports skipped blocks and keeps the picker open', async () => {
        vi.spyOn(controller, '_jsonRequest').mockResolvedValue({
            sectionId: 55,
            skippedBlockCount: 2,
            skippedBlockTypes: ['map'],
            unknownFields: [],
        });
        vi.spyOn(controller, '_afterStructuralOp').mockImplementation(() => {});
        vi.spyOn(controller, '_mountSectionSettings').mockImplementation(() => {});
        controller.templatePickerTarget.hidden = false;

        await controller._confirmInsert({ id: 7 });

        expect(controller.templatePickerStatusTarget.textContent).toMatch(/map/);
        expect(controller.templatePickerStatusTarget.textContent).toMatch(/2/);
        expect(controller.templatePickerTarget.hidden).toBe(false);
    });

    it('does nothing further when the insert request fails', async () => {
        vi.spyOn(controller, '_jsonRequest').mockResolvedValue(null);
        const afterSpy = vi.spyOn(controller, '_afterStructuralOp').mockImplementation(() => {});

        await controller._confirmInsert({ id: 7 });

        expect(afterSpy).not.toHaveBeenCalled();
    });
});

describe('cb-builder template picker: delete', () => {
    let controller;

    beforeEach(() => {
        ({ controller } = setupController({ areaId: 99 }));
        vi.spyOn(console, 'log').mockImplementation(() => {});
    });

    it('requires confirmation before deleting', async () => {
        const confirmSpy = vi.spyOn(window, 'confirm').mockReturnValue(false);
        const reqSpy = vi.spyOn(controller, '_jsonRequest').mockResolvedValue(null);

        await controller._deleteTemplate({ id: 3 }, '');

        expect(confirmSpy).toHaveBeenCalled();
        expect(reqSpy).not.toHaveBeenCalled();
    });

    it('DELETEs then reloads the list from the top on confirm', async () => {
        vi.spyOn(window, 'confirm').mockReturnValue(true);
        const reqSpy = vi.spyOn(controller, '_jsonRequest').mockResolvedValue({ deleted: true });
        const loadSpy = vi.spyOn(controller, '_loadTemplates').mockResolvedValue();

        await controller._deleteTemplate({ id: 3 }, 'hero');

        expect(reqSpy).toHaveBeenCalledWith('DELETE', '/_content-blocks/section-templates/3');
        expect(loadSpy).toHaveBeenCalledWith('hero', 0, false);
    });
});

describe('cb-builder template picker: save section as template', () => {
    let controller;

    beforeEach(() => {
        ({ controller } = setupController({ areaId: 99 }));
        vi.spyOn(console, 'log').mockImplementation(() => {});
    });

    it('prompts for a name and posts the snapshot, then lands on a re-fetched library', async () => {
        vi.spyOn(window, 'prompt').mockReturnValue('  My hero  ');
        const reqSpy = vi.spyOn(controller, '_jsonRequest').mockResolvedValue({ id: 1, name: 'My hero' });
        const flashSpy = vi.spyOn(controller, '_flashSaved').mockImplementation(() => {});
        global.fetch = vi.fn(() => okJson({
            items: [{ id: 1, name: 'My hero', insertable: true, canManage: false }],
            hasMore: false,
        }));
        controller._templateItems = [{ id: 1, name: 'stale' }];

        await controller._saveSectionAsTemplate(12);

        expect(reqSpy).toHaveBeenCalledWith(
            'POST',
            '/_content-blocks/section/12/save-as-template',
            { name: 'My hero' },
        );
        expect(flashSpy).toHaveBeenCalled();
        // The stale cache was dropped and the list re-fetched: the editor lands
        // on the library seeing the entry they just created, not the old one.
        expect(global.fetch).toHaveBeenCalled();
        expect(controller._templateItems).toEqual([
            { id: 1, name: 'My hero', insertable: true, canManage: false },
        ]);
    });

    it('leaves the sidebar on its default state, not the section it saved', async () => {
        vi.spyOn(window, 'prompt').mockReturnValue('My hero');
        vi.spyOn(controller, '_jsonRequest').mockResolvedValue({ id: 1, name: 'My hero' });
        vi.spyOn(controller, '_flashSaved').mockImplementation(() => {});
        const resetSpy = vi.spyOn(controller, '_resetSidebarToEmptyState').mockImplementation(() => {});
        global.fetch = vi.fn(() => okJson({ items: [], hasMore: false }));

        await controller._saveSectionAsTemplate(12);

        // Saving runs from the section toolbar, so without this the panel stays
        // on that section's settings and hides the thing just created.
        expect(resetSpy).toHaveBeenCalled();
    });

    it('does not touch the sidebar when the save failed', async () => {
        vi.spyOn(window, 'prompt').mockReturnValue('My hero');
        vi.spyOn(controller, '_jsonRequest').mockResolvedValue(null);
        const resetSpy = vi.spyOn(controller, '_resetSidebarToEmptyState').mockImplementation(() => {});
        controller._templateItems = [{ id: 1, name: 'stale' }];

        await controller._saveSectionAsTemplate(12);

        // Yanking the editor out of the form they were on would lose the
        // context they need to retry.
        expect(resetSpy).not.toHaveBeenCalled();
        expect(controller._templateItems).toEqual([{ id: 1, name: 'stale' }]);
    });

    it('aborts on cancelled or blank name without any request', async () => {
        const reqSpy = vi.spyOn(controller, '_jsonRequest').mockResolvedValue(null);

        vi.spyOn(window, 'prompt').mockReturnValueOnce(null);
        await controller._saveSectionAsTemplate(12);

        vi.spyOn(window, 'prompt').mockReturnValue('   ');
        await controller._saveSectionAsTemplate(12);

        expect(reqSpy).not.toHaveBeenCalled();
    });
});

describe('cb-builder template picker: poster thumbnails', () => {
    let controller, list;

    beforeEach(() => {
        ({ controller, list } = setupController());
        vi.spyOn(console, 'log').mockImplementation(() => {});
        vi.spyOn(console, 'error').mockImplementation(() => {});
    });

    /** Paints one item and returns its rendered card. */
    async function paint(item) {
        global.fetch = vi.fn(() => okJson({ items: [item], hasMore: false }));
        await controller.openTemplatePicker();

        return list.querySelector('.cb-template-picker__item');
    }

    const poster = (columns, layout = 'full') => ({ layout, columns });
    const tile = (kind, extra = {}) => ({ kind, text: null, image: null, label: 'Block', missing: false, ...extra });

    it('draws one tile per block, in payload order', async () => {
        const card = await paint({
            id: 1,
            name: 'Hero',
            insertable: true,
            canManage: false,
            poster: poster([{ width: 12, more: 0, tiles: [
                tile('heading', { text: 'Our story' }),
                tile('text', { text: 'Some words' }),
                tile('button', { text: 'Buy' }),
            ] }]),
        });

        const tiles = card.querySelectorAll('.cb-template-poster__tile');
        expect(tiles).toHaveLength(3);
        expect(tiles[0].classList.contains('cb-template-poster__tile--heading')).toBe(true);
        expect(tiles[0].textContent).toBe('Our story');
        expect(tiles[2].classList.contains('cb-template-poster__tile--button')).toBe(true);
    });

    it('carries the real column presets so proportions survive', async () => {
        const card = await paint({
            id: 2,
            name: 'Split',
            insertable: true,
            canManage: false,
            poster: poster([
                { width: 8, more: 0, tiles: [] },
                { width: 4, more: 0, tiles: [] },
            ], 'two_cols'),
        });

        const cols = card.querySelectorAll('.cb-template-poster__col');
        expect(cols).toHaveLength(2);
        expect(cols[0].style.flexGrow).toBe('8');
        expect(cols[1].style.flexGrow).toBe('4');
    });

    it('renders a picture as a lazy <img> pointing at the stored file', async () => {
        const card = await paint({
            id: 3,
            name: 'Gallery',
            insertable: true,
            canManage: false,
            poster: poster([{ width: 12, more: 0, tiles: [tile('image', { image: '/uploads/a.png' })] }]),
        });

        const img = card.querySelector('.cb-template-poster__tile--image img');
        expect(img).not.toBeNull();
        expect(img.getAttribute('src')).toBe('/uploads/a.png');
        expect(img.loading).toBe('lazy');
        // Decorative inside an already-labelled card.
        expect(img.alt).toBe('');
    });

    it('falls back to the block label when the stored upload is gone', async () => {
        // Templates reference uploads by path, so deleting the file leaves the
        // tile pointing at nothing. A broken-image glyph would read as "the
        // thumbnail is broken" rather than "that picture is gone".
        const card = await paint({
            id: 10,
            name: 'Hero',
            insertable: true,
            canManage: false,
            poster: poster([{ width: 12, more: 0, tiles: [tile('image', { image: '/uploads/gone.png', label: 'Image' })] }]),
        });

        const el = card.querySelector('.cb-template-poster__tile');
        el.querySelector('img').dispatchEvent(new Event('error'));

        expect(el.querySelector('img')).toBeNull();
        expect(el.classList.contains('cb-template-poster__tile--generic')).toBe(true);
        expect(el.classList.contains('cb-template-poster__tile--image')).toBe(false);
        expect(el.textContent).toBe('Image');
    });

    it('names a block that has nothing to preview instead of drawing a blank', async () => {
        const card = await paint({
            id: 4,
            name: 'Mixed',
            insertable: true,
            canManage: false,
            poster: poster([{ width: 12, more: 0, tiles: [
                tile('generic', { label: 'Tableau' }),
                tile('image', { image: null, label: 'Image' }),
            ] }]),
        });

        const tiles = card.querySelectorAll('.cb-template-poster__tile');
        expect(tiles[0].textContent).toBe('Tableau');
        // A picture whose path the server refused still says what it is.
        expect(tiles[1].textContent).toBe('Image');
        expect(tiles[1].querySelector('img')).toBeNull();
    });

    it('marks a tile whose block type is gone', async () => {
        const card = await paint({
            id: 5,
            name: 'Legacy',
            insertable: true,
            skippedTypes: ['map'],
            canManage: false,
            poster: poster([{ width: 12, more: 0, tiles: [tile('generic', { label: 'map', missing: true })] }]),
        });

        const missing = card.querySelector('.cb-template-poster__tile--missing');
        expect(missing).not.toBeNull();
        expect(missing.textContent).toBe('map');
    });

    it('paints the section background on the frame, flagging a dark one', async () => {
        const card = await paint({
            id: 11,
            name: 'Hero',
            insertable: true,
            canManage: false,
            poster: {
                layout: 'full',
                background: '#101828',
                dark: true,
                columns: [{ width: 12, more: 0, tiles: [tile('heading', { text: 'Dark hero' })] }],
            },
        });

        const frame = card.querySelector('.cb-template-poster');
        expect(frame.style.background).toBe('rgb(16, 24, 40)');
        expect(frame.classList.contains('cb-template-poster--tinted')).toBe(true);
        // The server decided this from the colour's luminance; the class is
        // what repaints the tiles so their copy survives the ground.
        expect(frame.classList.contains('cb-template-poster--dark')).toBe(true);
    });

    it('tints without the dark treatment when the background is light', async () => {
        const card = await paint({
            id: 12,
            name: 'Hero',
            insertable: true,
            canManage: false,
            poster: {
                layout: 'full',
                background: '#faf5ee',
                dark: false,
                columns: [{ width: 12, more: 0, tiles: [] }],
            },
        });

        const frame = card.querySelector('.cb-template-poster');
        expect(frame.classList.contains('cb-template-poster--tinted')).toBe(true);
        expect(frame.classList.contains('cb-template-poster--dark')).toBe(false);
    });

    it('leaves the frame untouched when the section has no background', async () => {
        const card = await paint({
            id: 13,
            name: 'Hero',
            insertable: true,
            canManage: false,
            poster: poster([{ width: 12, more: 0, tiles: [] }]),
        });

        const frame = card.querySelector('.cb-template-poster');
        expect(frame.style.background).toBe('');
        expect(frame.classList.contains('cb-template-poster--tinted')).toBe(false);
    });

    it('carries a block-level background onto its tile', async () => {
        const card = await paint({
            id: 14,
            name: 'Cards',
            insertable: true,
            canManage: false,
            poster: poster([{ width: 12, more: 0, tiles: [
                tile('heading', { text: 'Coloured', background: '#eb0540' }),
                tile('heading', { text: 'Plain' }),
            ] }]),
        });

        const tiles = card.querySelectorAll('.cb-template-poster__tile');
        expect(tiles[0].style.background).toBe('rgb(235, 5, 64)');
        expect(tiles[1].style.background).toBe('');
    });

    it('flips a coloured tile\'s copy according to its own colour, not the section\'s', async () => {
        const card = await paint({
            id: 15,
            name: 'Cards',
            insertable: true,
            canManage: false,
            poster: {
                layout: 'full',
                background: '#faf5ee',
                dark: false,
                columns: [{ width: 12, more: 0, tiles: [
                    tile('heading', { text: 'Dark card', background: '#eb0540', backgroundDark: true }),
                    tile('heading', { text: 'Pale card', background: '#fff8e1', backgroundDark: false }),
                ] }],
            },
        });

        const tiles = card.querySelectorAll('.cb-template-poster__tile');
        expect(tiles[0].classList.contains('cb-template-poster__tile--on-dark')).toBe(true);
        expect(tiles[1].classList.contains('cb-template-poster__tile--on-light')).toBe(true);
    });

    it('folds the blocks beyond the cap into a +N chip', async () => {
        const card = await paint({
            id: 6,
            name: 'Long',
            insertable: true,
            canManage: false,
            poster: poster([{ width: 12, more: 4, tiles: [tile('heading', { text: 'x' })] }]),
        });

        expect(card.querySelector('.cb-template-poster__more').textContent).toBe('+4');
    });

    it('leaves the poster out of the accessibility tree, keeping the name as the label', async () => {
        const card = await paint({
            id: 7,
            name: 'Hero',
            insertable: true,
            canManage: false,
            poster: poster([{ width: 12, more: 0, tiles: [tile('heading', { text: 'Our story' })] }]),
        });

        expect(card.querySelector('.cb-template-poster').getAttribute('aria-hidden')).toBe('true');
        expect(card.querySelector('.cb-template-picker__item-name').textContent).toBe('Hero');
    });

    it('falls back to a plain named card when the payload has nothing to draw', async () => {
        for (const noPoster of [undefined, null, { layout: 'full', columns: [] }]) {
            const card = await paint({ id: 8, name: 'Ancient', insertable: true, canManage: false, poster: noPoster });

            expect(card.querySelector('.cb-template-poster')).toBeNull();
            expect(card.querySelector('.cb-template-picker__item-name').textContent).toBe('Ancient');
        }
    });

    it('still renders the delete affordance over a card that has a poster', async () => {
        const card = await paint({
            id: 9,
            name: 'Hero',
            insertable: true,
            canManage: true,
            poster: poster([{ width: 12, more: 0, tiles: [tile('rule')] }]),
        });

        expect(card.querySelector('.cb-template-picker__delete')).not.toBeNull();
        // A rule carries no copy — it must not fall back to a label.
        expect(card.querySelector('.cb-template-poster__tile--rule').textContent).toBe('');
    });
});
