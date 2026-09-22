import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import Controller from '../controllers/cb-builder_controller.js';

/**
 * The import/export panel: the media switch on export, the size cap checked
 * before sending, and what the editor reads when media or room is missing.
 */

function setup({ maxBytes = '1048576', withMedia = true } = {}) {
    document.body.innerHTML = `
        <div data-controller="cb-builder" data-cb-api-base="/cb" data-cb-csrf-token="tok">
            <div class="cb-import-export-picker" data-cb-import-max-bytes="${maxBytes}"></div>
            <input type="checkbox" class="media" ${withMedia ? 'checked' : ''}>
            <input type="file" class="file">
            <p class="status"></p>
        </div>
    `;
    const element = document.querySelector('[data-controller="cb-builder"]');
    const controller = new Controller();
    const define = (name, value) => Object.defineProperty(controller, name, { value });
    define('element', element);
    define('areaIdValue', 7);
    define('hasImportExportPickerTarget', true);
    define('importExportPickerTarget', element.querySelector('.cb-import-export-picker'));
    define('hasExportAssetsTarget', true);
    define('exportAssetsTarget', element.querySelector('.media'));
    define('hasImportFileTarget', true);
    define('hasImportExportStatusTarget', true);
    define('importExportStatusTarget', element.querySelector('.status'));

    return { controller, status: element.querySelector('.status') };
}

function pick(controller, size) {
    const file = new File(['{}'], 'export.json', { type: 'application/json' });
    Object.defineProperty(file, 'size', { value: size });
    Object.defineProperty(controller, 'importFileTarget', {
        value: { files: [file], value: 'export.json' },
    });
}

describe('cb-builder import/export', () => {
    let clicked;

    beforeEach(() => {
        clicked = [];
        vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(function () {
            clicked.push(this.getAttribute('href'));
        });
        vi.spyOn(window, 'confirm').mockReturnValue(true);
    });

    afterEach(() => {
        vi.restoreAllMocks();
    });

    it('exports with media by default', () => {
        const { controller } = setup();
        controller.runExport();
        expect(clicked).toEqual(['/cb/area/7/export']);
    });

    it('exports without media when the switch is off', () => {
        const { controller } = setup({ withMedia: false });
        controller.runExport();
        expect(clicked).toEqual(['/cb/area/7/export?assets=0']);
    });

    it('refuses a file over the cap before sending it', async () => {
        const { controller, status } = setup({ maxBytes: '1048576' });
        const fetchSpy = vi.spyOn(globalThis, 'fetch');
        pick(controller, 3 * 1024 * 1024);

        await controller.runImport();

        expect(fetchSpy).not.toHaveBeenCalled();
        expect(status.textContent).toMatch(/3\.0 MB.*1\.0 MB/);
    });

    it("reads a proxy's HTML 413 as too large, not as a generic failure", async () => {
        const { controller, status } = setup({ maxBytes: '0' });
        vi.spyOn(globalThis, 'fetch').mockResolvedValue(
            new Response('<html>413</html>', { status: 413 }),
        );
        pick(controller, 10);

        await controller.runImport();

        expect(status.textContent).toBe('This file is too large for the server.');
    });

    it('names the missing media, three at most', () => {
        const { controller } = setup();

        const message = controller._missingAssetsWarning({
            missingAssets: ['/u/a.png', '/u/b.png', '/u/c.png', '/u/d.png'],
        });

        expect(message).toBe('4 media file(s) not found on this site: /u/a.png, /u/b.png, /u/c.png, …');
        expect(controller._missingAssetsWarning({ missingAssets: [] })).toBeNull();
        expect(controller._missingAssetsWarning(null)).toBeNull();
    });
});
