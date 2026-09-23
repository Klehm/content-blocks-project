import { describe, it, expect, vi, beforeEach } from 'vitest';

vi.mock('../transfer/import-source.js', () => ({
    openExport: vi.fn(async () => ({ manifest: {}, media: new Map() })),
    SourceError: class SourceError extends Error {},
}));
vi.mock('../transfer/import-flow.js', () => {
    class RequestError extends Error {
        constructor(code, message) {
            super(message);
            this.code = code;
        }
    }
    class ImportRun {
        constructor() {
            Object.assign(this, ImportRun.state);
        }

        async plan() { return this; }

        get sendBytes() { return 2048; }

        label(hash) { return `${hash}.png`; }

        async send(onProgress) {
            onProgress({ done: 0, count: 1, bytes: 0, total: 2048 });
            if (ImportRun.failWith) throw ImportRun.failWith;
        }

        async commit() { return ImportRun.result; }
    }
    return { ImportRun, RequestError };
});

const { TransferDialog } = await import('../transfer/transfer-dialog.js');
const { ImportRun, RequestError } = await import('../transfer/import-flow.js');
const { openExport, SourceError } = await import('../transfer/import-source.js');

const STRINGS = {
    stats: '%sections%s %blocks%b %media%m',
    content: '%sections% sections, %blocks% blocks',
    media_here: '%count% here',
    media_send: '%count% to send (%size%)',
    media_missing: '%count% missing: %names%',
    unknown_types: 'unknown: %types%',
    done: 'imported %sections%',
    missing_assets: '%count% not found: %names%',
    invalid: 'not an export',
    error_refused: 'refused: %message%',
    error: 'failed',
    sending: '%done%/%count%',
    writing: 'writing',
    reading: 'reading',
};

function setup() {
    document.body.innerHTML = `
        <dialog data-cb-transfer-strings='${JSON.stringify(STRINGS)}'>
            <button data-cb-transfer="close"></button>
            <button data-cb-transfer-tab="export" aria-selected="true"></button>
            <button data-cb-transfer-tab="import" aria-selected="false"></button>
            <section data-cb-transfer-panel="export">
                <p data-cb-transfer="stats"></p>
                <input type="checkbox" data-cb-transfer="media" checked>
                <span data-cb-transfer="size"></span>
                <a data-cb-transfer="download"></a>
            </section>
            <section data-cb-transfer-panel="import" hidden>
                <label data-cb-transfer="drop"><input type="file" data-cb-transfer="file"></label>
                <p data-cb-transfer="pick-message"></p>
                <span data-cb-transfer="file-name"></span><span data-cb-transfer="file-size"></span>
                <ul data-cb-transfer="checks"></ul>
                <label data-cb-transfer="supply" hidden><input type="file" data-cb-transfer="supply-file"></label>
                <p data-cb-transfer="review-message"></p>
                <button data-cb-transfer="run"></button>
                <p data-cb-transfer="progress-label"></p><progress data-cb-transfer="progress"></progress>
                <p data-cb-transfer="result"></p><ul data-cb-transfer="warnings"></ul>
                <p data-cb-transfer="error"></p>
            </section>
        </dialog>`;
    const dialog = document.querySelector('dialog');
    dialog.showModal = vi.fn(() => dialog.setAttribute('open', ''));
    dialog.close = vi.fn(() => dialog.removeAttribute('open'));
    const request = vi.fn(async () => new Response(JSON.stringify({
        sectionCount: 2, blockCount: 5, mediaCount: 3, size: 3 * 1024 * 1024, sizeWithoutMedia: 2048,
    })));
    const onImported = vi.fn();
    const transfer = new TransferDialog(dialog, { exportUrl: '/cb/area/7/export', request, onImported });
    const el = (name) => dialog.querySelector(`[data-cb-transfer="${name}"]`);
    return { dialog, transfer, request, onImported, el };
}

const flush = () => new Promise((resolve) => setTimeout(resolve, 0));

async function choose(el) {
    const input = el('file');
    Object.defineProperty(input, 'files', { value: [new File(['x'], 'home.zip')], configurable: true });
    input.dispatchEvent(new Event('change'));
    await flush();
}

describe('transfer dialog', () => {
    beforeEach(() => {
        ImportRun.state = {
            summary: { sectionCount: 2, blockCount: 5, mediaCount: 3 },
            have: ['a'], toSend: ['b'], missing: ['c'], tooLarge: [],
            unknownBlockTypes: ['countdown'], maxAssetBytes: 0,
        };
        ImportRun.failWith = null;
        ImportRun.result = { sectionCount: 2, missingAssets: ['/u/c.png'], hasUnpublishedChanges: true };
    });

    it('shows what the export holds, and follows the media switch', async () => {
        const { transfer, el } = setup();
        transfer.open();
        await flush();

        expect(el('stats').textContent).toBe('2s 5b 3m');
        expect(el('size').textContent).toBe('3 MB');
        expect(el('download').getAttribute('href')).toBe('/cb/area/7/export');

        el('media').checked = false;
        el('media').dispatchEvent(new Event('change'));

        expect(el('size').textContent).toBe('2 KB');
        expect(el('download').getAttribute('href')).toBe('/cb/area/7/export?assets=0');
    });

    it('reviews a dropped file before anything is written', async () => {
        const { dialog, transfer, el } = setup();
        transfer.open('import');
        await choose(el);

        expect(dialog.dataset.step).toBe('review');
        expect(el('file-name').textContent).toBe('home.zip');
        expect([...el('checks').children].map((li) => [li.className.split('--')[1], li.textContent])).toEqual([
            ['ok', '2 sections, 5 blocks'],
            ['ok', '1 here'],
            ['info', '1 to send (2 KB)'],
            ['warn', '1 missing: c.png'],
            ['warn', 'unknown: countdown'],
        ]);
        expect(el('supply').hidden).toBe(false);
    });

    it('says so when the file is not an export', async () => {
        openExport.mockRejectedValueOnce(new SourceError('invalid'));
        const { dialog, transfer, el } = setup();
        transfer.open('import');
        await choose(el);

        expect(dialog.dataset.step).toBe('pick');
        expect(el('pick-message').textContent).toBe('not an export');
    });

    it('runs, then reports the outcome and hands it to the builder', async () => {
        const { dialog, transfer, onImported, el } = setup();
        transfer.open('import');
        await choose(el);

        el('run').click();
        await flush();

        expect(dialog.dataset.step).toBe('done');
        expect(el('result').textContent).toBe('imported 2');
        expect(el('warnings').textContent).toBe('1 not found: c.png');
        expect(onImported).toHaveBeenCalledWith(ImportRun.result);
    });

    it('holds Escape while it runs, and shows a refusal', async () => {
        let release;
        ImportRun.failWith = new RequestError('refused', 'type not allowed');
        const { dialog, transfer, el } = setup();
        transfer.open('import');
        await choose(el);
        const gate = new Promise((resolve) => { release = resolve; });
        const send = ImportRun.prototype.send;
        ImportRun.prototype.send = async function (onProgress) {
            await gate;
            return send.call(this, onProgress);
        };

        el('run').click();
        const cancel = new Event('cancel', { cancelable: true });
        dialog.dispatchEvent(cancel);
        expect(cancel.defaultPrevented).toBe(true);
        release();
        await flush();
        ImportRun.prototype.send = send;

        expect(dialog.dataset.step).toBe('error');
        expect(el('error').textContent).toBe('refused: type not allowed');
    });
});
