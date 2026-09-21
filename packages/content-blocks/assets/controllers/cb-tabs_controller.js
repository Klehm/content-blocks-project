import { Controller } from '@hotwired/stimulus';

/**
 * Sidebar field groups: tabs from `cb_group`, collapsible panels from
 * `cb_panel`. Purely DOM — every field stays rendered for autosave.
 *
 * @see docs/internals/forms.md#sidebar-tabs-and-panels
 */
export default class extends Controller {
    static targets = ['tab', 'panel', 'fold'];
    static values = {
        active: { type: String, default: '0' },
        storageKey: { type: String, default: '' },
    };

    connect() {
        const saved = this._read();
        if (saved.tab !== undefined && this.tabTargets.some((tab) => tab.dataset.cbTab === saved.tab)) {
            this.activeValue = saved.tab;
        }
        this._show(this.activeValue);
        this._restoreFolds(saved.panels ?? {});

        this._onToggle = this._onToggle.bind(this);
        this._onField = this._onField.bind(this);
        // `toggle` does not bubble: only a capturing listener sees it here.
        this.element.addEventListener('toggle', this._onToggle, true);
        this.element.addEventListener('input', this._onField);
        this.element.addEventListener('change', this._onField);
    }

    disconnect() {
        this.element.removeEventListener('toggle', this._onToggle, true);
        this.element.removeEventListener('input', this._onField);
        this.element.removeEventListener('change', this._onField);
    }

    foldTargetConnected(fold) {
        this._summarize(fold);
    }

    select(event) {
        event.preventDefault();
        const index = event.currentTarget.dataset.cbTab;
        if (index === undefined || index === this.activeValue) return;
        this.activeValue = index;
        this._show(index);
        this._write({ tab: index });
    }

    _show(index) {
        this.tabTargets.forEach((tab) => {
            const isActive = tab.dataset.cbTab === index;
            tab.classList.toggle('cb-sidebar-tabs__tab--active', isActive);
            tab.setAttribute('aria-selected', isActive ? 'true' : 'false');
        });
        this.panelTargets.forEach((panel) => {
            panel.hidden = panel.dataset.cbTab !== index;
        });
    }

    // ---------- Panels ----------

    /** A group holding an invalid field keeps the server's pick open. */
    _restoreFolds(saved) {
        const groups = new Map();
        for (const fold of this.foldTargets) {
            const group = fold.dataset.cbPanelGroup ?? '';
            if (!groups.has(group)) groups.set(group, []);
            groups.get(group).push(fold);
        }
        for (const [group, folds] of groups) {
            if (!(group in saved)) continue;
            if (folds.some((fold) => fold.classList.contains('cb-panel--has-error'))) continue;
            const wanted = saved[group];
            if (wanted !== '' && !folds.some((fold) => fold.dataset.cbPanel === wanted)) continue;
            folds.forEach((fold) => { fold.open = fold.dataset.cbPanel === wanted; });
        }
    }

    _onToggle(event) {
        const fold = event.target;
        if (!this.foldTargets.includes(fold)) return;
        const group = fold.dataset.cbPanelGroup ?? '';
        const panels = { ...(this._read().panels ?? {}) };
        if (fold.open) {
            panels[group] = fold.dataset.cbPanel;
        } else if (panels[group] === fold.dataset.cbPanel || !(group in panels)) {
            panels[group] = '';
        } else {
            return;
        }
        this._write({ panels });
    }

    _onField(event) {
        const fold = event.target?.closest?.('details[data-cb-panel]');
        if (fold && this.foldTargets.includes(fold)) this._summarize(fold);
    }

    /**
     * What a closed panel holds, read off its fields: the header then says
     * "40 · 12 · 0 · 12" instead of hiding it behind a click.
     */
    _summarize(fold) {
        const out = fold.querySelector(':scope > summary .cb-panel__summary');
        if (!out || out.hasAttribute('data-cb-summary-fixed')) return;
        const body = fold.querySelector(':scope > .cb-panel__body');
        const parts = [];
        for (const row of body?.children ?? []) {
            if (row.hidden) continue;
            const part = this._rowSummary(row);
            if (part !== '') parts.push(part);
        }
        const text = parts.join(' · ');
        if (text === '') {
            out.removeAttribute('data-cb-summary');
        } else {
            out.setAttribute('data-cb-summary', text);
        }
        fold.toggleAttribute('data-cb-filled', text !== '');
    }

    _rowSummary(row) {
        const parts = [];
        const numbers = [];
        const seen = new Set();
        const fields = row.querySelectorAll('input[name], select[name], textarea[name]');
        for (const field of fields) {
            if (!this._countsTowardSummary(field) || seen.has(field.name)) continue;
            if (field.type === 'radio') {
                seen.add(field.name);
                const checked = Array.from(fields).find((f) => f.name === field.name && f.checked);
                if (checked && checked.value !== '') parts.push(this._choiceText(checked));
            } else if (field.type === 'checkbox') {
                if (field.checked) parts.push(this._choiceText(field));
            } else if (field.tagName === 'SELECT') {
                const text = this._selectText(field, row);
                if (text !== '') parts.push(text);
            } else if (field.type === 'hidden') {
                // Only an upload's stored path gets this far.
                if (field.value !== '') parts.push(field.value.split('/').pop());
            } else if (this._isRangeFloor(field)) {
                continue;
            } else {
                numbers.push(field.value.trim());
            }
        }
        if (numbers.some((value) => value !== '')) {
            const values = numbers.length > 1 ? numbers.map((v) => (v === '' ? '0' : v)) : numbers;
            const unit = row.querySelector('.cb-length__unit');
            const unitText = unit?.tagName === 'SELECT' ? unit.selectedOptions[0]?.text : unit?.textContent;
            parts.unshift(values.join(' · ') + (unitText ? ` ${unitText.trim()}` : ''));
        }

        return parts.join(' · ');
    }

    _countsTowardSummary(field) {
        if (field.disabled || field.type === 'file' || field.type === 'color') return false;
        if (field.name.endsWith('[linked]') || field.name.endsWith('[_token]')) return false;
        if (field.classList.contains('cb-length__unit')) return false;
        if (field.type === 'hidden' && !field.closest('.cb-image-upload')) return false;
        // Responsive fields: the desktop value speaks for the row.
        const viewport = field.closest('[data-viewport]');

        return !viewport || viewport.dataset.viewport === 'desktop';
    }

    /** A slider always holds a number; resting on its minimum means unset. */
    _isRangeFloor(field) {
        if (!field.closest('.cb-form-range-wrap')) return false;
        const min = field.getAttribute('min') ?? '0';

        return field.value.trim() === '' || Number(field.value) === Number(min);
    }

    _choiceText(input) {
        const label = input.closest('label') ?? input.labels?.[0];

        return (label?.getAttribute('title') || label?.textContent || input.value).trim();
    }

    _selectText(select, row) {
        if (select.value === '') return '';
        if (select.value === 'custom') {
            const color = row.querySelector('input[type="color"]');
            if (color) return color.value;
        }

        return (select.selectedOptions[0]?.text ?? select.value).trim();
    }

    // ---------- Memory ----------

    /** Per sidebar kind, for the session: a tab or panel left open stays so. */
    _read() {
        if (this.storageKeyValue === '') return {};
        try {
            return JSON.parse(window.sessionStorage.getItem(this._key()) ?? '{}') ?? {};
        } catch (_) {
            return {};
        }
    }

    _write(patch) {
        if (this.storageKeyValue === '') return;
        try {
            window.sessionStorage.setItem(this._key(), JSON.stringify({ ...this._read(), ...patch }));
        } catch (_) {
            // Private mode or a full quota: the sidebar simply forgets.
        }
    }

    _key() {
        return `cb-sidebar:${this.storageKeyValue}`;
    }
}
