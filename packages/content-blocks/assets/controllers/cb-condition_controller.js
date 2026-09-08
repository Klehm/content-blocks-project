import { Controller } from '@hotwired/stimulus';

/*
 * Generic conditional-field visibility from `data-cb-condition`: `|` is OR
 * within a clause, `;` is AND between clauses. Hidden fields still submit.
 */
export default class extends Controller {
    connect() {
        this._onInput = () => this._syncAll();
        this.element.addEventListener('input', this._onInput);
        this.element.addEventListener('change', this._onInput);
        this._syncAll();
    }

    disconnect() {
        this.element.removeEventListener('input', this._onInput);
        this.element.removeEventListener('change', this._onInput);
    }

    _syncAll() {
        for (const row of this.element.querySelectorAll('[data-cb-condition]')) {
            // Instances nest, and a row belongs to its *nearest* ancestor
            // only — or an outer one resolves it against the wrong scope.
            const scope = row.closest('[data-controller~="cb-condition"]');
            if (scope && scope !== this.element && this.element.contains(scope)) continue;
            const specs = this._parse(row.getAttribute('data-cb-condition'));
            if (!specs) continue;
            // AND across clauses: every clause must match for the row to show.
            row.hidden = !specs.every((spec) => this._matches(spec));
        }
    }

    _parse(raw) {
        if (typeof raw !== 'string' || raw.trim() === '') return null;
        const specs = raw
            .split(';')
            .map((clause) => this._parseClause(clause))
            .filter((spec) => spec !== null);
        return specs.length > 0 ? specs : null;
    }

    _parseClause(raw) {
        const clause = raw.trim();
        if (clause === '') return null;
        const idx = clause.indexOf(':');
        if (idx === -1) {
            return { field: clause, values: null };
        }
        const field = clause.slice(0, idx).trim();
        const values = clause
            .slice(idx + 1)
            .split('|')
            .map((v) => v.trim());
        return field === '' ? null : { field, values };
    }

    _matches({ field, values }) {
        const value = this._fieldValue(field);
        if (value === undefined) {
            // No matching control in scope — leave the row visible rather
            // than hiding content because of a typo in the attribute.
            return true;
        }
        if (values === null) {
            return value !== '';
        }
        return values.includes(value);
    }

    _fieldValue(field) {
        const controls = this.element.querySelectorAll(
            `[name$="[${field}]"], [name="${field}"]`,
        );
        if (controls.length === 0) return undefined;

        const first = controls[0];

        if (first.type === 'checkbox') {
            return first.checked ? 'true' : 'false';
        }

        if (first.type === 'radio') {
            for (const radio of controls) {
                if (radio.checked) return radio.value;
            }
            return '';
        }

        return first.value ?? '';
    }
}
