import { Controller } from '@hotwired/stimulus';

/*
 * Generic conditional-field visibility controller.
 *
 * Attach to any element wrapping form fields (typically via a form type's
 * `attr` option, or on the sidebar form itself). Descendants declare their
 * visibility rule with a `data-cb-condition` attribute:
 *
 *     data-cb-condition="palette:custom"        shown when the `palette`
 *                                               field's value is "custom"
 *     data-cb-condition="size:custom|full"      OR — any listed value matches
 *     data-cb-condition="stylingCustom:true"    checkbox — checked maps to
 *                                               "true", unchecked to "false"
 *     data-cb-condition="link"                  no value — shown when the
 *                                               field is non-empty
 *
 * The field part matches the *last bracket segment* of the controlling
 * input's `name` (Symfony nests names like `settings[styling][bg][palette]`),
 * or a plain top-level `name="palette"`. The lookup is scoped to the
 * controller element, so attaching the controller on a compound type's root
 * keeps two instances of the same sub-field independent.
 *
 * Rows are toggled with the native `hidden` attribute so the behavior is
 * stylesheet-independent (works in the section sidebar and the block edit
 * form alike). Hidden fields still submit — persistence-side handling (e.g.
 * dropping the styling subtree when the switch is off) is a server concern.
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
            // Instances nest (e.g. a palette color field inside a sidebar
            // form, both carrying this controller): each row belongs to its
            // *nearest* cb-condition ancestor only, so an outer instance
            // never resolves an inner row's field against the wrong scope.
            const scope = row.closest('[data-controller~="cb-condition"]');
            if (scope && scope !== this.element && this.element.contains(scope)) continue;
            const spec = this._parse(row.getAttribute('data-cb-condition'));
            if (!spec) continue;
            row.hidden = !this._matches(spec);
        }
    }

    _parse(raw) {
        if (typeof raw !== 'string' || raw.trim() === '') return null;
        const idx = raw.indexOf(':');
        if (idx === -1) {
            return { field: raw.trim(), values: null };
        }
        const field = raw.slice(0, idx).trim();
        const values = raw
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
