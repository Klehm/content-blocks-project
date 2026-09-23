/**
 * Reads a zip's directory from a Blob and hands each entry back as a Blob,
 * sliced from the file rather than loaded. Stored and deflated entries only.
 *
 * @see docs/internals/transfer.md#the-zip
 */

const EOCD = 0x06054b50;
const ZIP64_LOCATOR = 0x07064b50;
const ZIP64_EOCD = 0x06064b50;
const CENTRAL = 0x02014b50;
const LOCAL = 0x04034b50;
const MAX_U32 = 0xffffffff;

export class ZipError extends Error {}

async function view(blob, start, end) {
    return new DataView(await blob.slice(start, end).arrayBuffer());
}

function u64(dv, at) {
    const value = dv.getBigUint64(at, true);
    if (value > BigInt(Number.MAX_SAFE_INTEGER)) throw new ZipError('Archive too large.');
    return Number(value);
}

/** The end-of-directory record, searched from the end: a comment may follow. */
async function findDirectory(blob) {
    const tailStart = Math.max(0, blob.size - 65557);
    const tail = await view(blob, tailStart, blob.size);
    for (let at = tail.byteLength - 22; at >= 0; at -= 1) {
        if (tail.getUint32(at, true) !== EOCD) continue;
        let count = tail.getUint16(at + 10, true);
        let size = tail.getUint32(at + 12, true);
        let offset = tail.getUint32(at + 16, true);
        if (count === 0xffff || size === MAX_U32 || offset === MAX_U32) {
            const locatorAt = at - 20;
            if (locatorAt < 0 || tail.getUint32(locatorAt, true) !== ZIP64_LOCATOR) {
                throw new ZipError('Broken ZIP64 archive.');
            }
            const recordAt = u64(tail, locatorAt + 8);
            const record = await view(blob, recordAt, recordAt + 56);
            if (record.getUint32(0, true) !== ZIP64_EOCD) throw new ZipError('Broken ZIP64 archive.');
            count = u64(record, 32);
            size = u64(record, 40);
            offset = u64(record, 48);
        }
        return { count, size, offset };
    }
    throw new ZipError('Not a zip archive.');
}

/** ZIP64 extended information: only the fields saturated in the header. */
function zip64Extra(dv, start, length, entry) {
    for (let at = start; at + 4 <= start + length;) {
        const id = dv.getUint16(at, true);
        const size = dv.getUint16(at + 2, true);
        if (id === 0x0001) {
            let field = at + 4;
            for (const key of ['size', 'compressedSize', 'offset']) {
                if (entry[key] === MAX_U32) {
                    entry[key] = u64(dv, field);
                    field += 8;
                }
            }
            return;
        }
        at += 4 + size;
    }
}

/**
 * @returns {Promise<Map<string, {name: string, size: number,
 *   compressedSize: number, method: number, offset: number}>>}
 */
export async function readDirectory(blob) {
    const { count, size, offset } = await findDirectory(blob);
    if (offset + size > blob.size) throw new ZipError('Truncated archive.');
    const dv = await view(blob, offset, offset + size);
    const decoder = new TextDecoder();
    const entries = new Map();
    let at = 0;
    for (let i = 0; i < count; i += 1) {
        if (at + 46 > dv.byteLength || dv.getUint32(at, true) !== CENTRAL) {
            throw new ZipError('Broken zip directory.');
        }
        const nameLength = dv.getUint16(at + 28, true);
        const extraLength = dv.getUint16(at + 30, true);
        const commentLength = dv.getUint16(at + 32, true);
        const entry = {
            name: decoder.decode(new Uint8Array(dv.buffer, dv.byteOffset + at + 46, nameLength)),
            method: dv.getUint16(at + 10, true),
            compressedSize: dv.getUint32(at + 20, true),
            size: dv.getUint32(at + 24, true),
            offset: dv.getUint32(at + 42, true),
        };
        zip64Extra(dv, at + 46 + nameLength, extraLength, entry);
        entries.set(entry.name, entry);
        at += 46 + nameLength + extraLength + commentLength;
    }
    return entries;
}

/** An entry's content: a slice of the file when stored, inflated otherwise. */
export async function entryBlob(blob, entry) {
    const local = await view(blob, entry.offset, entry.offset + 30);
    if (local.getUint32(0, true) !== LOCAL) throw new ZipError('Broken zip entry.');
    const start = entry.offset + 30 + local.getUint16(26, true) + local.getUint16(28, true);
    const data = blob.slice(start, start + entry.compressedSize);
    if (data.size !== entry.compressedSize) throw new ZipError('Truncated archive.');
    if (entry.method === 0) return data;
    if (entry.method !== 8) throw new ZipError('Unsupported compression.');
    const inflated = await new Response(
        data.stream().pipeThrough(new DecompressionStream('deflate-raw')),
    ).blob();
    if (inflated.size !== entry.size) throw new ZipError('Broken zip entry.');
    return inflated;
}
