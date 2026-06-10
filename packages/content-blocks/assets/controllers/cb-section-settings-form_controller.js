import { Controller } from '@hotwired/stimulus';

/**
 * Hijacks the section settings form so its submit doesn't navigate the
 * admin page away. Posts the FormData via fetch; on success, fires
 * `cb:section:saved` so the parent cb-builder controller can close the
 * sidebar and reload the iframe. On a validation 422, swaps the
 * sidebar HTML with the re-rendered form so the user sees the errors.
 */
export default class extends Controller {
    static targets = ['form', 'maxWidthRow', 'widthsField', 'widthInput', 'widthsTotal', 'customRow', 'customToggle'];
    static values = { sectionId: Number };

    connect() {
        this._onSubmit = this._onSubmit.bind(this);
        this._onChange = this._onChange.bind(this);
        if (this.hasFormTarget) {
            this.formTarget.addEventListener('submit', this._onSubmit);
            this.formTarget.addEventListener('change', this._onChange);
            // Server-rendered visibility is correct on first paint; this
            // call only matters if the form was re-rendered by a 422
            // (validation) swap and the user had toggled widthMode
            // before saving.
            this._syncMaxWidthVisibility();
        }
        // Seed the column-width inputs from the stored CSV (or an equal split
        // for display when none is set yet) and paint the running total.
        this._initWidths();
    }

    disconnect() {
        if (this.hasFormTarget) {
            this.formTarget.removeEventListener('submit', this._onSubmit);
            this.formTarget.removeEventListener('change', this._onChange);
        }
    }

    /**
     * Watches the widthMode radio group; the maxWidth row is only
     * meaningful when the section is "centered" (BuiltInSectionDecorator
     * ignores maxWidth in "full" mode).
     */
    _onChange(event) {
        const name = event.target?.name;
        if (typeof name === 'string' && name.endsWith('[widthMode]')) {
            this._syncMaxWidthVisibility();
        }
    }

    _syncMaxWidthVisibility() {
        if (!this.hasMaxWidthRowTarget) return;
        const checked = this.formTarget.querySelector('input[name$="[widthMode]"]:checked');
        this.maxWidthRowTarget.hidden = checked?.value !== 'centered';
    }

    // ---------- Column widths ----------

    _initWidths() {
        if (!this.hasWidthsFieldTarget || this.widthInputTargets.length === 0) return;
        const value = this.widthsFieldTarget.value;
        const stored = this._parseCsv(value);
        const count = this.widthInputTargets.length;
        // Stored value wins; otherwise show an equal split as a starting point
        // WITHOUT committing it (the field stays empty → equal layout).
        const widths = stored.length === count ? stored : this._equalSplit(count);
        this._setInputs(widths);
        this._paintTotal(this._sum(widths));
        // Reflect the current value in the UI: highlight the matching preset,
        // or reveal + flag the free inputs when it's a custom (non-preset) one.
        this._syncActive(value);
    }

    /** Preset button: apply a fixed split. */
    applyWidthPreset(event) {
        const raw = event.currentTarget.dataset.cbWidths || '';
        const widths = this._parseCsv(raw);
        this._setInputs(widths);
        this._paintTotal(this._sum(widths));
        this._commitWidths(raw);
        this._syncActive(raw);
    }

    /** "Custom" button: reveal the free inputs and focus the first one. */
    showCustomWidths() {
        this._showCustom();
        this.widthInputTargets[0]?.focus();
    }

    /**
     * Highlight the preset matching `value`; when it's a custom value (no
     * preset matches) reveal the free inputs and flag the Custom button.
     * An empty value (no explicit width = framework default) highlights the
     * first/equal preset since that's what the columns render as.
     */
    _syncActive(value) {
        this._clearActive();
        const buttons = this._presetButtons();
        const match = buttons.find((b) => b.dataset.cbWidths === value);
        if (match) {
            match.classList.add('cb-col-widths__preset--active');
            this._hideCustom();
            return;
        }
        if (value !== '') {
            this._showCustom(); // custom value → flag the Custom button
            return;
        }
        // Default (no width set): the equal/first preset reflects the render.
        buttons[0]?.classList.add('cb-col-widths__preset--active');
        this._hideCustom();
    }

    _clearActive() {
        this.element.querySelectorAll('.cb-col-widths__preset--active')
            .forEach((b) => b.classList.remove('cb-col-widths__preset--active'));
    }

    /** The numeric preset buttons (excludes the "Custom" toggle). */
    _presetButtons() {
        return Array.from(this.element.querySelectorAll('.cb-col-widths__preset[data-cb-widths]'));
    }

    _showCustom() {
        if (this.hasCustomRowTarget) this.customRowTarget.hidden = false;
        if (this.hasCustomToggleTarget) {
            this._clearActive();
            this.customToggleTarget.classList.add('cb-col-widths__preset--active');
        }
    }

    _hideCustom() {
        if (this.hasCustomRowTarget) this.customRowTarget.hidden = true;
        if (this.hasCustomToggleTarget) {
            this.customToggleTarget.classList.remove('cb-col-widths__preset--active');
        }
    }

    /** Live edit: for 2 columns the sibling auto-completes to keep sum 100. */
    onWidthInput(event) {
        const inputs = this.widthInputTargets;
        if (inputs.length === 2) {
            const idx = inputs.indexOf(event.currentTarget);
            const v = this._clamp(parseInt(event.currentTarget.value, 10));
            if (v !== null) inputs[1 - idx].value = String(100 - v);
        }
        const widths = this._currentWidths();
        this._paintTotal(this._sum(widths));
        if (this._isValid(widths)) this._commitWidths(widths.join(','));
    }

    /** On blur/change: snap to a valid set (fix the last input) then commit. */
    onWidthCommit() {
        let widths = this._currentWidths().map((n) => this._clamp(n) ?? 0);
        const head = widths.slice(0, -1);
        const last = 100 - this._sum(head);
        if (last >= 1 && last <= 99) {
            widths = [...head, last];
            this._setInputs(widths);
        }
        this._paintTotal(this._sum(widths));
        if (this._isValid(widths)) this._commitWidths(widths.join(','));
    }

    /** Write the canonical value and let cb-autosave persist + reload preview. */
    _commitWidths(csv) {
        if (!this.hasWidthsFieldTarget) return;
        if (this.widthsFieldTarget.value === csv) return; // no-op
        this.widthsFieldTarget.value = csv;
        // Bubbles to the form root where cb-autosave listens; its serialized
        // diff turns this into a single save (and one debounced iframe reload).
        this.widthsFieldTarget.dispatchEvent(new Event('change', { bubbles: true }));
    }

    _currentWidths() {
        return this.widthInputTargets.map((el) => parseInt(el.value, 10) || 0);
    }

    _setInputs(widths) {
        this.widthInputTargets.forEach((el, i) => {
            if (widths[i] !== undefined) el.value = String(widths[i]);
        });
    }

    _paintTotal(sum) {
        if (!this.hasWidthsTotalTarget) return;
        const ok = sum === 100;
        this.widthsTotalTarget.textContent = ok ? '100% ✓' : `${sum}%`;
        this.widthsTotalTarget.classList.toggle('cb-col-widths__total--invalid', !ok);
    }

    _isValid(widths) {
        return widths.length >= 2
            && widths.every((n) => Number.isInteger(n) && n >= 1 && n <= 99)
            && this._sum(widths) === 100;
    }

    _parseCsv(value) {
        if (typeof value !== 'string' || value === '') return [];
        return value.split(',').map((p) => parseInt(p.trim(), 10)).filter((n) => Number.isFinite(n));
    }

    _equalSplit(count) {
        if (count <= 0) return [];
        const base = Math.floor(100 / count);
        const widths = new Array(count).fill(base);
        widths[0] += 100 - base * count; // absorb the remainder in the first
        return widths;
    }

    _clamp(n) {
        if (!Number.isFinite(n)) return null;
        return Math.max(1, Math.min(99, n));
    }

    _sum(widths) {
        return widths.reduce((a, b) => a + (Number.isFinite(b) ? b : 0), 0);
    }

    async _onSubmit(event) {
        event.preventDefault();
        if (!this.hasFormTarget) return;

        const csrfToken = this.element.closest('[data-cb-csrf-token]')?.dataset.cbCsrfToken || '';
        const formData = new FormData(this.formTarget);

        const response = await fetch(this.formTarget.action, {
            method: 'POST',
            body: formData,
            credentials: 'same-origin',
            headers: { 'X-CSRF-Token': csrfToken },
        });

        if (response.ok) {
            this.element.dispatchEvent(new CustomEvent('cb:section:saved', {
                bubbles: true,
                detail: { sectionId: this.sectionIdValue },
            }));
            return;
        }

        if (response.status === 422) {
            // Validation errors — swap the sidebar HTML with the new form.
            const html = await response.text();
            this.element.outerHTML = html;
            return;
        }

        console.error('[cb-section-settings-form] save failed', response.status);
    }
}
