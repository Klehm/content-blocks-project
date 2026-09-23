import { describe } from './import-source.js';

/**
 * An import in small requests: plan, one request per file to send, content.
 * The server re-checks each file; this only decides what is worth sending.
 *
 * @see docs/internals/transfer.md#an-import-in-steps
 */

export class RequestError extends Error {
    constructor(status, payload) {
        super(payload?.error ?? `HTTP ${status}`);
        this.status = status;
        this.code = status === 413 ? 'too_large' : payload?.code ?? null;
    }
}

export class ImportRun {
    /**
     * @param {object} source    what openExport() returned
     * @param {Function} request (path, init) => Promise<Response>
     */
    constructor(source, request) {
        this.source = source;
        this.request = request;
        this.summary = describe(source.manifest);
        this.have = [];
        this.toSend = [];
        this.missing = [];
        this.tooLarge = [];
        this.unknownBlockTypes = [];
        this.maxAssetBytes = 0;
    }

    async _json(path, init) {
        const response = await this.request(path, init);
        const payload = await response.json().catch(() => null);
        if (!response.ok) throw new RequestError(response.status, payload);
        return payload;
    }

    _post(path, body) {
        return this._json(path, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(body),
        });
    }

    async plan() {
        const assets = {};
        for (const [hash, asset] of Object.entries(this.source.manifest.assets)) {
            assets[hash] = { path: asset?.path ?? null, size: asset?.size ?? null };
        }
        const plan = await this._post('/import/plan', {
            assets,
            blockTypes: this.summary.blockTypes,
        });
        this.have = plan.have ?? [];
        this.unknownBlockTypes = plan.unknownBlockTypes ?? [];
        this.maxAssetBytes = plan.maxAssetBytes ?? 0;
        this.toSend = [];
        this.missing = [];
        this.tooLarge = [];
        for (const hash of plan.need ?? []) {
            const file = this.source.media.get(hash);
            if (!file) this.missing.push(hash);
            else if (this.maxAssetBytes > 0 && file.size > this.maxAssetBytes) this.tooLarge.push(hash);
            else this.toSend.push(hash);
        }
        return this;
    }

    get sendBytes() {
        return this.toSend.reduce((sum, hash) => sum + this.source.media.get(hash).size, 0);
    }

    _upload(blob, name, hash) {
        const body = new FormData();
        body.append('file', blob, name);
        if (hash) body.append('hash', hash);
        return this._json('/import/asset', { method: 'POST', body });
    }

    _name(hash) {
        const extension = this.source.manifest.assets[hash]?.extension;
        return `${hash}.${/^[a-z0-9]{1,10}$/i.test(extension ?? '') ? extension : 'bin'}`;
    }

    /** Sends each file in turn; a failure stops, and a new plan resumes. */
    async send(onProgress = () => {}) {
        const total = this.sendBytes;
        let sent = 0;
        const queue = [...this.toSend];
        for (const [index, hash] of queue.entries()) {
            onProgress({ done: index, count: queue.length, bytes: sent, total });
            const file = this.source.media.get(hash);
            await this._upload(await file.blob(), this._name(hash), hash);
            sent += file.size;
            this.toSend = this.toSend.filter((h) => h !== hash);
            this.have.push(hash);
        }
        onProgress({ done: queue.length, count: queue.length, bytes: total, total });
    }

    /**
     * Files the editor drops for the missing ones. The server hashes each and
     * keeps only a file the plan expects.
     */
    async supply(files) {
        const accepted = [];
        const refused = [];
        for (const file of files) {
            try {
                const { hash } = await this._upload(file, file.name);
                if (!this.have.includes(hash)) this.have.push(hash);
                this.missing = this.missing.filter((h) => h !== hash);
                this.tooLarge = this.tooLarge.filter((h) => h !== hash);
                accepted.push(file.name);
            } catch (e) {
                if (!(e instanceof RequestError)) throw e;
                refused.push({ name: file.name, code: e.code, message: e.message });
            }
        }
        return { accepted, refused };
    }

    commit() {
        return this._post('/import/commit', this.source.manifest);
    }

    /** Where the manifest says a file came from, to name it to the editor. */
    label(hash) {
        const path = this.source.manifest.assets[hash]?.path;
        return typeof path === 'string' && path !== '' ? path.split('/').pop() : hash.slice(0, 12);
    }
}
