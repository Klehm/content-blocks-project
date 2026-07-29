import { describe, it, expect, beforeEach } from 'vitest';
import Controller from '../controllers/cb-builder_controller.js';

/**
 * Unit tests for how an import that succeeded *with reservations* is reported.
 *
 * The backend never refuses an import over its content: a payload comes from
 * another installation, so referencing a block type this app doesn't have is
 * normal. It warns instead — and the editor has to actually see the warning,
 * which is the part that had no coverage.
 */

function setupController() {
    document.body.innerHTML = `
        <div data-controller="cb-builder">
            <div class="cb-import-export" hidden></div>
            <p class="cb-import-export__status"></p>
        </div>
    `;
    const element = document.querySelector('[data-controller="cb-builder"]');
    const panel = element.querySelector('.cb-import-export');
    const status = element.querySelector('.cb-import-export__status');

    const controller = new Controller();
    Object.defineProperty(controller, 'element', { value: element });
    Object.defineProperty(controller, 'hasImportExportTarget', { value: true });
    Object.defineProperty(controller, 'importExportTarget', { value: panel });
    Object.defineProperty(controller, 'hasImportExportStatusTarget', { value: true });
    Object.defineProperty(controller, 'importExportStatusTarget', { value: status });

    return { controller, panel, status };
}

describe('cb-builder import warnings', () => {
    let controller;

    beforeEach(() => {
        ({ controller } = setupController());
    });

    it('reports nothing for a clean import', () => {
        expect(controller._importWarning({
            imported: true,
            sectionCount: 2,
            missingBlockTypes: [],
            unknownFields: [],
        })).toBeNull();
    });

    it('names the block types this app has no renderer for', () => {
        const message = controller._importWarning({
            missingBlockTypes: ['countdown', 'map'],
            unknownFields: [],
        });

        expect(message).toMatch(/countdown, map/);
    });

    it('falls back to unknown stored fields, deduplicating the block types', () => {
        const message = controller._importWarning({
            missingBlockTypes: [],
            unknownFields: [
                { blockType: 'text', unknownKeys: ['legacy'] },
                { blockType: 'text', unknownKeys: ['other'] },
                { blockType: 'title', unknownKeys: ['old'] },
            ],
        });

        expect(message).toMatch(/text, title/);
        expect(message).not.toMatch(/text, text/);
    });

    it('prefers the missing types when both are present', () => {
        // A block nothing can render is worse news than a stray stored field.
        const message = controller._importWarning({
            missingBlockTypes: ['countdown'],
            unknownFields: [{ blockType: 'text', unknownKeys: ['legacy'] }],
        });

        expect(message).toMatch(/countdown/);
        expect(message).not.toMatch(/text/);
    });

    it('tolerates a response missing the warning keys entirely', () => {
        expect(controller._importWarning({ imported: true })).toBeNull();
        expect(controller._importWarning(null)).toBeNull();
    });
});
