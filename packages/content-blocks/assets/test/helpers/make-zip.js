import { deflateRawSync } from 'node:zlib';

/**
 * Builds a zip in memory for tests: stored or deflated entries, and a ZIP64
 * spelling of the same records to exercise that reader path.
 */

const CRC_TABLE = Array.from({ length: 256 }, (_, n) => {
    let c = n;
    for (let k = 0; k < 8; k += 1) c = c & 1 ? 0xedb88320 ^ (c >>> 1) : c >>> 1;
    return c >>> 0;
});

function crc32(bytes) {
    let crc = 0xffffffff;
    for (const byte of bytes) crc = CRC_TABLE[(crc ^ byte) & 0xff] ^ (crc >>> 8);
    return (crc ^ 0xffffffff) >>> 0;
}

/**
 * @param {Array<{
 *   name: string,
 *   data: string|Uint8Array,
 *   deflate?: boolean,
 * }>} files
 * @param {{zip64?: boolean}} options
 * @returns {Uint8Array}
 */
export function makeZip(files, { zip64 = false } = {}) {
    const chunks = [];
    const central = [];
    let offset = 0;
    const encoder = new TextEncoder();

    for (const file of files) {
        const raw = typeof file.data === 'string' ? encoder.encode(file.data) : file.data;
        const body = file.deflate ? new Uint8Array(deflateRawSync(raw)) : raw;
        const name = encoder.encode(file.name);
        const crc = crc32(raw);
        const method = file.deflate ? 8 : 0;

        const local = new DataView(new ArrayBuffer(30));
        local.setUint32(0, 0x04034b50, true);
        local.setUint16(4, 20, true);
        local.setUint16(8, method, true);
        local.setUint32(14, crc, true);
        local.setUint32(18, body.length, true);
        local.setUint32(22, raw.length, true);
        local.setUint16(26, name.length, true);
        chunks.push(new Uint8Array(local.buffer), name, body);

        const extra = zip64 ? new DataView(new ArrayBuffer(28)) : null;
        if (extra) {
            extra.setUint16(0, 0x0001, true);
            extra.setUint16(2, 24, true);
            extra.setBigUint64(4, BigInt(raw.length), true);
            extra.setBigUint64(12, BigInt(body.length), true);
            extra.setBigUint64(20, BigInt(offset), true);
        }
        const header = new DataView(new ArrayBuffer(46));
        header.setUint32(0, 0x02014b50, true);
        header.setUint16(4, 45, true);
        header.setUint16(6, 20, true);
        header.setUint16(10, method, true);
        header.setUint32(16, crc, true);
        header.setUint32(20, zip64 ? 0xffffffff : body.length, true);
        header.setUint32(24, zip64 ? 0xffffffff : raw.length, true);
        header.setUint16(28, name.length, true);
        header.setUint16(30, extra ? 28 : 0, true);
        header.setUint32(42, zip64 ? 0xffffffff : offset, true);
        central.push(new Uint8Array(header.buffer), name);
        if (extra) central.push(new Uint8Array(extra.buffer));

        offset += 30 + name.length + body.length;
    }

    const centralSize = central.reduce((sum, c) => sum + c.length, 0);
    const tail = [];
    if (zip64) {
        const record = new DataView(new ArrayBuffer(56));
        record.setUint32(0, 0x06064b50, true);
        record.setBigUint64(4, 44n, true);
        record.setBigUint64(24, BigInt(files.length), true);
        record.setBigUint64(32, BigInt(files.length), true);
        record.setBigUint64(40, BigInt(centralSize), true);
        record.setBigUint64(48, BigInt(offset), true);
        const locator = new DataView(new ArrayBuffer(20));
        locator.setUint32(0, 0x07064b50, true);
        locator.setBigUint64(8, BigInt(offset + centralSize), true);
        locator.setUint32(16, 1, true);
        tail.push(new Uint8Array(record.buffer), new Uint8Array(locator.buffer));
    }
    const end = new DataView(new ArrayBuffer(22));
    end.setUint32(0, 0x06054b50, true);
    end.setUint16(8, zip64 ? 0xffff : files.length, true);
    end.setUint16(10, zip64 ? 0xffff : files.length, true);
    end.setUint32(12, zip64 ? 0xffffffff : centralSize, true);
    end.setUint32(16, zip64 ? 0xffffffff : offset, true);

    const parts = [...chunks, ...central, ...tail, new Uint8Array(end.buffer)];
    const out = new Uint8Array(parts.reduce((sum, p) => sum + p.length, 0));
    let at = 0;
    for (const part of parts) {
        out.set(part, at);
        at += part.length;
    }
    return out;
}
