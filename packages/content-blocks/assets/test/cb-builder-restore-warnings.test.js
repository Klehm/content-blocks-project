import { describe, it, expect, beforeEach } from 'vitest';
import Controller from '../controllers/cb-builder_controller.js';

/**
 * Unit tests for how a restore that succeeded *with reservations* is reported.
 *
 * The backend never refuses over content: a payload comes from another
 * installation, so referencing a block type this app doesn't have is normal.
 * Those blocks are skipped and reported — and the editor has to actually see
 * the report, which is the part that had no coverage.
 *
 * `_restoreWarning` is shared by the import and the template-insert flows;
 * only the wording differs, so the caller passes its own [key, fallback] pairs.
 */

const MESSAGES = {
    skipped: ['cb.builder.import_export.skipped_blocks', 'Imported — %count% block(s) skipped, missing type(s): %types%'],
    unknown: ['cb.builder.import_export.unknown_fields', 'Imported, but some stored fields are unknown on: %types%'],
};

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

describe('cb-builder restore warnings', () => {
    let controller;

    beforeEach(() => {
        ({ controller } = setupController());
    });

    it('reports nothing for a clean import', () => {
        expect(controller._restoreWarning({
            imported: true,
            sectionCount: 2,
            skippedBlockCount: 0,
            skippedBlockTypes: [],
            unknownFields: [],
        }, MESSAGES)).toBeNull();
    });

    it('names the types of the blocks that were left out, and how many', () => {
        const message = controller._restoreWarning({
            skippedBlockCount: 3,
            skippedBlockTypes: ['countdown', 'map'],
            unknownFields: [],
        }, MESSAGES);

        expect(message).toMatch(/countdown, map/);
        expect(message).toMatch(/3/);
    });

    it('falls back to unknown stored fields, deduplicating the block types', () => {
        const message = controller._restoreWarning({
            skippedBlockTypes: [],
            unknownFields: [
                { blockType: 'text', unknownKeys: ['legacy'] },
                { blockType: 'text', unknownKeys: ['other'] },
                { blockType: 'title', unknownKeys: ['old'] },
            ],
        }, MESSAGES);

        expect(message).toMatch(/text, title/);
        expect(message).not.toMatch(/text, text/);
    });

    it('prefers the skipped blocks when both are present', () => {
        // Content that did not come in at all is worse news than a stray
        // stored field on content that did.
        const message = controller._restoreWarning({
            skippedBlockCount: 1,
            skippedBlockTypes: ['countdown'],
            unknownFields: [{ blockType: 'text', unknownKeys: ['legacy'] }],
        }, MESSAGES);

        expect(message).toMatch(/countdown/);
        expect(message).not.toMatch(/text/);
    });

    it('tolerates a response missing the warning keys entirely', () => {
        expect(controller._restoreWarning({ imported: true }, MESSAGES)).toBeNull();
        expect(controller._restoreWarning(null, MESSAGES)).toBeNull();
    });
});
