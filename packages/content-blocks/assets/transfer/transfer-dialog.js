import { openExport, SourceError } from './import-source.js';
import { ImportRun, RequestError } from './import-flow.js';

/**
 * The Import / Export dialog: the export's summary and media switch, then an
 * import told before it runs, sent in steps, with its outcome.
 *
 * @see docs/internals/frontend.md#the-import-export-dialog
 */
export class TransferDialog {
    /**
     * @param {HTMLDialogElement} dialog
     * @param {object} options
     * @param {string} options.exportUrl
     * @param {Function} options.request    (path, init) => Promise<Response>
     * @param {Function} options.onImported (result) => void
     */
    constructor(dialog, { exportUrl, request, onImported }) {
        this.dialog = dialog;
        this.exportUrl = exportUrl;
        this.request = request;
        this.onImported = onImported;
        this.strings = JSON.parse(dialog.dataset.cbTransferStrings || '{}');
        this.summary = null;
        this.run = null;
        this.busy = false;
        this._bind();
    }

    _el(name) {
        return this.dialog.querySelector(`[data-cb-transfer="${name}"]`);
    }

    _t(key, vars = {}) {
        let text = this.strings[key] ?? key;
        for (const [name, value] of Object.entries(vars)) {
            text = text.replaceAll(`%${name}%`, String(value));
        }
        return text;
    }

    _size(bytes) {
        const units = (this.strings.units ?? 'B|KB|MB|GB').split('|');
        let value = bytes;
        let unit = 0;
        while (value >= 1024 && unit < units.length - 1) {
            value /= 1024;
            unit += 1;
        }
        const lang = document.documentElement.lang || undefined;
        return `${value.toLocaleString(lang, { maximumFractionDigits: unit < 2 ? 0 : 1 })} ${units[unit]}`;
    }

    _bind() {
        this.dialog.addEventListener('click', (event) => {
            const action = event.target.closest('[data-cb-transfer]')?.dataset.cbTransfer;
            if (action === 'close') this.close();
            if (action === 'reset') this._reset();
            if (action === 'run') this._run();
            if (action === 'retry') this._retry();
            const tab = event.target.closest('[data-cb-transfer-tab]');
            if (tab) this._select(tab.dataset.cbTransferTab);
        });
        this.dialog.addEventListener('keydown', (event) => {
            const tab = event.target.closest('[data-cb-transfer-tab]');
            if (!tab || !['ArrowLeft', 'ArrowRight'].includes(event.key)) return;
            event.preventDefault();
            this._select(tab.dataset.cbTransferTab === 'export' ? 'import' : 'export', true);
        });
        // Escape does not cut a run short: half the files would be sent.
        this.dialog.addEventListener('cancel', (event) => {
            if (this.busy) event.preventDefault();
        });
        this._el('media').addEventListener('change', () => this._renderExport());
        this._el('file').addEventListener('change', (event) => {
            const [file] = event.target.files;
            event.target.value = '';
            if (file) this._review(file);
        });
        this._el('supply-file').addEventListener('change', (event) => {
            const files = [...event.target.files];
            event.target.value = '';
            if (files.length) this._supply(files);
        });
        this._dropZone(this._el('drop'), (files) => this._review(files[0]));
        this._dropZone(this._el('supply'), (files) => this._supply(files));
    }

    /** Lit only for a drag of files; the depth count survives children. */
    _dropZone(zone, onFiles) {
        let depth = 0;
        const carriesFiles = (event) => [...(event.dataTransfer?.types ?? [])].includes('Files');
        zone.addEventListener('dragenter', (event) => {
            if (!carriesFiles(event)) return;
            event.preventDefault();
            depth += 1;
            zone.classList.add('is-over');
        });
        zone.addEventListener('dragover', (event) => {
            if (carriesFiles(event)) event.preventDefault();
        });
        zone.addEventListener('dragleave', () => {
            depth = Math.max(0, depth - 1);
            if (depth === 0) zone.classList.remove('is-over');
        });
        zone.addEventListener('drop', (event) => {
            if (!carriesFiles(event)) return;
            event.preventDefault();
            depth = 0;
            zone.classList.remove('is-over');
            const files = [...event.dataTransfer.files];
            if (files.length && !this.busy) onFiles(files);
        });
    }

    open(tab = 'export') {
        if (!this.dialog.open) this.dialog.showModal();
        this._select(tab);
        if (!this.busy && this.dialog.dataset.step !== 'review') this._step('pick');
    }

    close() {
        if (this.busy) return;
        this.dialog.close();
    }

    _select(name, focus = false) {
        for (const tab of this.dialog.querySelectorAll('[data-cb-transfer-tab]')) {
            const selected = tab.dataset.cbTransferTab === name;
            tab.setAttribute('aria-selected', String(selected));
            tab.tabIndex = selected ? 0 : -1;
            if (selected && focus) tab.focus();
        }
        for (const panel of this.dialog.querySelectorAll('[data-cb-transfer-panel]')) {
            panel.hidden = panel.dataset.cbTransferPanel !== name;
        }
        if (name === 'export') this._loadSummary();
    }

    _step(name) {
        this.dialog.dataset.step = name;
    }

    // ---------- Export ----------

    async _loadSummary() {
        this._renderExport();
        try {
            const response = await this.request('/export/summary', { method: 'GET' });
            if (!response.ok) return;
            this.summary = await response.json();
            this._renderExport();
        } catch (e) {
            // The download still works without the numbers.
        }
    }

    _renderExport() {
        const withMedia = this._el('media').checked;
        this._el('download').href = withMedia ? this.exportUrl : `${this.exportUrl}?assets=0`;
        const summary = this.summary;
        this._el('stats').textContent = summary
            ? this._t('stats', {
                sections: summary.sectionCount,
                blocks: summary.blockCount,
                media: summary.mediaCount,
            })
            : '';
        for (const [name, key] of [
            ['sections', 'sectionCount'],
            ['blocks', 'blockCount'],
            ['media', 'mediaCount'],
        ]) {
            const count = this._el(`count-${name}`);
            if (count) count.textContent = summary ? String(summary[key]) : '–';
        }
        this.dialog.classList.toggle('is-without-media', !withMedia);
        this._el('size').textContent = summary
            ? this._size(withMedia ? summary.size : summary.sizeWithoutMedia)
            : '';
    }

    // ---------- Import ----------

    _reset() {
        if (this.busy) return;
        this.run = null;
        this._el('pick-message').textContent = '';
        this._step('pick');
    }

    async _review(file) {
        this._select('import');
        this._step('pick');
        const message = this._el('pick-message');
        message.textContent = this._t('reading');
        this.file = file;
        try {
            this.run = new ImportRun(await openExport(file), this.request);
            await this.run.plan();
        } catch (e) {
            this.run = null;
            message.textContent = e instanceof SourceError ? this._t('invalid') : this._error(e);
            return;
        }
        message.textContent = '';
        this._el('file-name').textContent = file.name;
        this._el('file-size').textContent = this._size(file.size);
        this._el('review-message').textContent = '';
        this._renderChecks();
        this._step('review');
        this._el('run').focus();
    }

    _names(hashes) {
        const names = hashes.slice(0, 3).map((hash) => this.run.label(hash));
        return names.join(', ') + (hashes.length > 3 ? ', …' : '');
    }

    _renderChecks() {
        const run = this.run;
        const items = [['ok', this._t('content', {
            sections: run.summary.sectionCount,
            blocks: run.summary.blockCount,
        })]];
        if (run.summary.mediaCount === 0) items.push(['ok', this._t('media_none')]);
        if (run.have.length) items.push(['ok', this._t('media_here', { count: run.have.length })]);
        if (run.toSend.length) {
            items.push(['info', this._t('media_send', {
                count: run.toSend.length,
                size: this._size(run.sendBytes),
            })]);
        }
        if (run.missing.length) {
            items.push(['warn', this._t('media_missing', {
                count: run.missing.length,
                names: this._names(run.missing),
            })]);
        }
        if (run.tooLarge.length) {
            items.push(['warn', this._t('media_too_large', {
                count: run.tooLarge.length,
                max: this._size(run.maxAssetBytes),
                names: this._names(run.tooLarge),
            })]);
        }
        if (run.unknownBlockTypes.length) {
            items.push(['warn', this._t('unknown_types', { types: run.unknownBlockTypes.join(', ') })]);
        }
        this._list(this._el('checks'), items);
        this._el('supply').hidden = run.missing.length + run.tooLarge.length === 0;
    }

    _list(list, items) {
        list.replaceChildren(...items.map(([tone, text]) => {
            const item = document.createElement('li');
            item.className = `cb-transfer__check cb-transfer__check--${tone}`;
            item.textContent = text;
            return item;
        }));
    }

    async _supply(files) {
        if (!this.run || this.busy) return;
        this.busy = true;
        try {
            const { refused } = await this.run.supply(files);
            const max = this._size(this.run.maxAssetBytes);
            this._el('review-message').textContent = refused.map(({ name, code, message }) => {
                if (code === 'too_large') return `${name} — ${this._t('error_too_large', { max })}`;
                if (code === 'refused') return `${name} — ${message}`;
                return this._t('supply_refused', { name });
            }).join(' ');
            this._renderChecks();
        } catch (e) {
            this._el('review-message').textContent = this._error(e);
        } finally {
            this.busy = false;
        }
    }

    async _run() {
        if (!this.run || this.busy) return;
        this.busy = true;
        this.dialog.classList.add('is-busy');
        this._step('run');
        const label = this._el('progress-label');
        const bar = this._el('progress');
        try {
            await this.run.send(({ done, count, bytes, total }) => {
                label.textContent = this._t('sending', {
                    done,
                    count,
                    sent: this._size(bytes),
                    total: this._size(total),
                });
                bar.value = total > 0 ? bytes / total : 1;
            });
            label.textContent = this._t('writing');
            bar.removeAttribute('value');
            const result = await this.run.commit();
            this._done(result);
            this.onImported?.(result);
        } catch (e) {
            this._el('error').textContent = this._error(e);
            this._step('error');
        } finally {
            this.busy = false;
            this.dialog.classList.remove('is-busy');
        }
    }

    async _retry() {
        if (!this.run) return this._reset();
        try {
            await this.run.plan();
        } catch (e) {
            this._el('error').textContent = this._error(e);
            return undefined;
        }
        return this._run();
    }

    _done(result) {
        this._el('result').textContent = this._t('done', { sections: result.sectionCount ?? 0 });
        const warnings = [];
        const skipped = result.skippedBlockTypes ?? [];
        if (skipped.length) {
            warnings.push(['warn', this._t('skipped_blocks', {
                count: result.skippedBlockCount ?? skipped.length,
                types: skipped.join(', '),
            })]);
        }
        const fields = result.unknownFields ?? [];
        if (fields.length) {
            const types = [...new Set(fields.map((f) => f.blockType))].join(', ');
            warnings.push(['warn', this._t('unknown_fields', { types })]);
        }
        const missing = result.missingAssets ?? [];
        if (missing.length) {
            const names = missing.slice(0, 3).map((p) => p.split('/').pop()).join(', ')
                + (missing.length > 3 ? ', …' : '');
            warnings.push(['warn', this._t('missing_assets', { count: missing.length, names })]);
        }
        this._list(this._el('warnings'), warnings);
        this._step('done');
    }

    _error(e) {
        if (e instanceof RequestError) {
            if (e.code === 'too_large') {
                return this._t('error_too_large', { max: this._size(this.run?.maxAssetBytes ?? 0) });
            }
            if (e.code) return this._t('error_refused', { message: e.message });
        }
        console.error('[cb-transfer]', e);
        return this._t('error');
    }
}
