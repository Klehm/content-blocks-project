import { readDirectory, entryBlob, ZipError } from './zip-reader.js';

/**
 * Opens a dropped export: the zip, or the base64 JSON of earlier versions.
 * Either way: the manifest without bytes, and each file as a Blob on demand.
 *
 * @see docs/internals/transfer.md#an-import-in-steps
 */

export const CONTENT = 'content.json';
export const MEDIA_DIR = 'media/';
const MAX_CONTENT_BYTES = 64 * 1024 * 1024;

export class SourceError extends Error {}

function base64Blob(data, type) {
    const binary = atob(data);
    const bytes = new Uint8Array(binary.length);
    for (let i = 0; i < binary.length; i += 1) bytes[i] = binary.charCodeAt(i);
    return new Blob([bytes], { type });
}

function checkManifest(manifest) {
    if (!manifest || typeof manifest !== 'object' || typeof manifest.format !== 'string'
        || !Array.isArray(manifest.contentArea?.sections)) {
        throw new SourceError('invalid');
    }
    const assets = manifest.assets;
    manifest.assets = assets && typeof assets === 'object' && !Array.isArray(assets) ? assets : {};
    return manifest;
}

async function fromZip(file) {
    const entries = await readDirectory(file);
    const content = entries.get(CONTENT);
    if (!content) throw new SourceError('invalid');
    if (content.size > MAX_CONTENT_BYTES) throw new SourceError('invalid');
    let manifest;
    try {
        manifest = JSON.parse(await (await entryBlob(file, content)).text());
    } catch (e) {
        throw new SourceError('invalid');
    }
    checkManifest(manifest);

    const byHash = new Map();
    for (const entry of entries.values()) {
        if (!entry.name.startsWith(MEDIA_DIR)) continue;
        byHash.set(entry.name.slice(MEDIA_DIR.length).split('.')[0], entry);
    }
    const media = new Map();
    for (const hash of Object.keys(manifest.assets)) {
        const entry = byHash.get(hash);
        if (entry) media.set(hash, { size: entry.size, blob: () => entryBlob(file, entry) });
    }
    return { manifest, media };
}

async function fromJson(file) {
    let manifest;
    try {
        manifest = JSON.parse(await file.text());
    } catch (e) {
        throw new SourceError('invalid');
    }
    checkManifest(manifest);

    const media = new Map();
    for (const [hash, asset] of Object.entries(manifest.assets)) {
        if (!asset || typeof asset.data !== 'string') continue;
        const { data, ...rest } = asset;
        const size = Math.floor((data.length * 3) / 4) - (data.endsWith('==') ? 2 : data.endsWith('=') ? 1 : 0);
        manifest.assets[hash] = { size, ...rest };
        media.set(hash, { size, blob: async () => base64Blob(data, rest.mimeType || '') });
    }
    return { manifest, media };
}

/** Blocks of the manifest, and the distinct types they use. */
export function describe(manifest) {
    let blocks = 0;
    const types = new Set();
    for (const section of manifest.contentArea.sections) {
        for (const column of section?.columns ?? []) {
            for (const block of column?.blocks ?? []) {
                blocks += 1;
                if (typeof block?.type === 'string') types.add(block.type);
            }
        }
    }
    return {
        sectionCount: manifest.contentArea.sections.length,
        blockCount: blocks,
        blockTypes: [...types],
        mediaCount: Object.keys(manifest.assets).length,
    };
}

export async function openExport(file) {
    const head = new Uint8Array(await file.slice(0, 4).arrayBuffer());
    const isZip = head[0] === 0x50 && head[1] === 0x4b;
    try {
        return { kind: isZip ? 'zip' : 'json', ...(isZip ? await fromZip(file) : await fromJson(file)) };
    } catch (e) {
        if (e instanceof ZipError) throw new SourceError('invalid');
        throw e;
    }
}
