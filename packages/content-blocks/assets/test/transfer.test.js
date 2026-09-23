// @vitest-environment node
import { describe, it, expect, vi } from 'vitest';
import { readFileSync } from 'node:fs';
import { readDirectory, entryBlob, ZipError } from '../transfer/zip-reader.js';
import { openExport, SourceError } from '../transfer/import-source.js';
import { ImportRun, RequestError } from '../transfer/import-flow.js';
import { makeZip } from './helpers/make-zip.js';

const fixture = () => new Blob([
    readFileSync(new URL('./fixtures/zipstream-export.zip', import.meta.url)),
]);
const FIXTURE_HASH = '6b7fa434f92a8b80aab02d9bf1a12e49ffcae424e4013a1c4f68b67e3d2bbcd0';

const manifest = (assets = {}, blocks = [{ type: 'title', data: {} }]) => ({
    format: 'content-blocks/v1',
    contentArea: { sections: [{ layout: 'full', columns: [{ preset: 'col-12', blocks }] }] },
    assets,
});

const zipOf = (files, options) => new Blob([makeZip(files, options)]);

describe('zip reader', () => {
    it('reads what zipstream-php writes: names, sizes, bytes', async () => {
        const blob = fixture();
        const entries = await readDirectory(blob);

        expect([...entries.keys()]).toEqual(['content.json', `media/${FIXTURE_HASH}.png`]);
        const png = await entryBlob(blob, entries.get(`media/${FIXTURE_HASH}.png`));
        const bytes = new Uint8Array(await png.arrayBuffer());
        expect(bytes.length).toBe(70);
        expect([...bytes.slice(1, 4)].map((b) => String.fromCharCode(b)).join('')).toBe('PNG');
    });

    it('inflates a deflated entry', async () => {
        const blob = zipOf([{ name: 'a.txt', data: 'hello '.repeat(50), deflate: true }]);
        const entries = await readDirectory(blob);

        expect(await (await entryBlob(blob, entries.get('a.txt'))).text()).toBe('hello '.repeat(50));
    });

    it('reads the ZIP64 spelling of sizes and offsets', async () => {
        const blob = zipOf([{ name: 'a.txt', data: 'first' }, { name: 'b.txt', data: 'second' }], { zip64: true });
        const entries = await readDirectory(blob);

        expect(entries.get('b.txt').size).toBe(6);
        expect(await (await entryBlob(blob, entries.get('b.txt'))).text()).toBe('second');
    });

    it('refuses what is not a zip, or is cut short', async () => {
        await expect(readDirectory(new Blob(['{"format":"x"}']))).rejects.toBeInstanceOf(ZipError);
        const whole = makeZip([{ name: 'a.txt', data: 'abc' }]);
        await expect(readDirectory(new Blob([whole.slice(10)]))).rejects.toBeInstanceOf(ZipError);
    });
});

describe('opening an export', () => {
    it('lists a zip export\'s files without reading them', async () => {
        const source = await openExport(fixture());

        expect(source.kind).toBe('zip');
        expect(source.manifest.contentArea.sections).toHaveLength(1);
        expect(source.media.get(FIXTURE_HASH).size).toBe(70);
    });

    it('turns an earlier base64 JSON into the same shape, bytes set aside', async () => {
        const json = JSON.stringify(manifest({
            abc: { mimeType: 'text/plain', extension: 'txt', data: btoa('bytes!') },
        }));
        const source = await openExport(new Blob([json]));

        expect(source.kind).toBe('json');
        expect(source.manifest.assets.abc).toEqual({ size: 6, mimeType: 'text/plain', extension: 'txt' });
        expect(await (await source.media.get('abc').blob()).text()).toBe('bytes!');
    });

    it('refuses a file that is not an export', async () => {
        await expect(openExport(new Blob(['not json']))).rejects.toBeInstanceOf(SourceError);
        await expect(openExport(zipOf([{ name: 'other.txt', data: 'x' }]))).rejects.toBeInstanceOf(SourceError);
        await expect(openExport(new Blob(['{"format":"x"}']))).rejects.toBeInstanceOf(SourceError);
    });
});

/** A server answering from a table: path => (init) => [status, body]. */
function server(routes) {
    const calls = [];
    const request = vi.fn(async (path, init) => {
        calls.push({ path, init });
        const [status, body] = routes[path](init, calls);
        return new Response(JSON.stringify(body), { status });
    });
    return { request, calls };
}

function sourceWith(files) {
    const assets = {};
    const media = new Map();
    for (const [hash, { size, path = null }] of Object.entries(files)) {
        assets[hash] = { extension: 'png', size, path };
        if (size !== null) media.set(hash, { size, blob: async () => new Blob(['x'.repeat(size)]) });
    }
    return { kind: 'zip', manifest: manifest(assets, [{ type: 'image' }, { type: 'countdown' }]), media };
}

describe('an import in steps', () => {
    it('sorts the plan: here, to send, missing, too large', async () => {
        const { request, calls } = server({
            '/import/plan': () => [200, {
                have: ['a'], need: ['b', 'c', 'd'], unknownBlockTypes: ['countdown'], maxAssetBytes: 100,
            }],
        });
        const run = await new ImportRun(sourceWith({
            a: { size: 10 }, b: { size: 20 }, c: { size: null, path: '/u/c.png' }, d: { size: 500 },
        }), request).plan();

        expect(JSON.parse(calls[0].init.body)).toEqual({
            assets: {
                a: { path: null, size: 10 },
                b: { path: null, size: 20 },
                c: { path: '/u/c.png', size: null },
                d: { path: null, size: 500 },
            },
            blockTypes: ['image', 'countdown'],
        });
        expect([run.have, run.toSend, run.missing, run.tooLarge]).toEqual([['a'], ['b'], ['c'], ['d']]);
        expect(run.unknownBlockTypes).toEqual(['countdown']);
        expect(run.sendBytes).toBe(20);
        expect(run.label('c')).toBe('c.png');
    });

    it('sends each file with its hash, reports progress, then commits', async () => {
        const { request, calls } = server({
            '/import/plan': () => [200, { have: [], need: ['a', 'b'], maxAssetBytes: 0 }],
            '/import/asset': (init) => [200, { hash: init.body.get('hash'), path: '/stored' }],
            '/import/commit': () => [200, { imported: true, sectionCount: 1 }],
        });
        const run = await new ImportRun(sourceWith({ a: { size: 3 }, b: { size: 5 } }), request).plan();
        const progress = [];

        await run.send((p) => progress.push(p.bytes));
        const result = await run.commit();

        const uploads = calls.filter((c) => c.path === '/import/asset');
        expect(uploads.map((c) => c.init.body.get('hash'))).toEqual(['a', 'b']);
        expect(uploads[0].init.body.get('file').name).toBe('a.png');
        expect(progress).toEqual([0, 3, 8]);
        expect(JSON.parse(calls.at(-1).init.body).assets.a).toEqual({ extension: 'png', size: 3, path: null });
        expect(result.imported).toBe(true);
    });

    it('stops at a refused file, keeping what was sent', async () => {
        const { request } = server({
            '/import/plan': () => [200, { have: [], need: ['a', 'b'] }],
            '/import/asset': (init) => (init.body.get('hash') === 'b'
                ? [400, { code: 'refused', error: 'type not allowed' }]
                : [200, { hash: 'a', path: '/a' }]),
        });
        const run = await new ImportRun(sourceWith({ a: { size: 1 }, b: { size: 1 } }), request).plan();

        await expect(run.send()).rejects.toMatchObject({ code: 'refused', message: 'type not allowed' });
        expect(run.have).toEqual(['a']);
        expect(run.toSend).toEqual(['b']);
    });

    it('reads a proxy\'s 413 as too large', () => {
        expect(new RequestError(413, null).code).toBe('too_large');
    });

    it('keeps the dropped files the server matches, and says which it refused', async () => {
        const { request } = server({
            '/import/plan': () => [200, { have: [], need: ['c'] }],
            '/import/asset': (init) => (init.body.get('file').name === 'c.png'
                ? [200, { hash: 'c', path: '/c' }]
                : [400, { code: 'unexpected_asset', error: 'not part' }]),
        });
        const run = await new ImportRun(sourceWith({ c: { size: null } }), request).plan();

        const outcome = await run.supply([new File(['x'], 'c.png'), new File(['y'], 'other.png')]);

        expect(outcome.accepted).toEqual(['c.png']);
        expect(outcome.refused).toEqual([{ name: 'other.png', code: 'unexpected_asset', message: 'not part' }]);
        expect(run.missing).toEqual([]);
        expect(run.have).toEqual(['c']);
    });
});
