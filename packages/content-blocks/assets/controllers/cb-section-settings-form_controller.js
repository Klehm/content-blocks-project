import { Controller } from '@hotwired/stimulus';

/**
 * Posts the section form by fetch rather than navigating away, firing
 * `cb:section:saved` on success and swapping in the errors on a 422.
 */
export default class extends Controller {
    static targets = ['form', 'maxWidthRow', 'widthsField', 'widthInput', 'widthsTotal', 'customRow', 'customToggle', 'columnLabel'];
    static values = { sectionId: Number };

    /** A label is typed; this is how long it waits for the next keystroke. */
    static COLUMN_LABEL_DEBOUNCE_MS = 500;

    connect() {
        this._onSubmit = this._onSubmit.bind(this);
        this._onChange = this._onChange.bind(this);
        if (this.hasFormTarget) {
            this.formTarget.addEventListener('submit', this._onSubmit);
            this.formTarget.addEventListener('change', this._onChange);
            // Only matters after a 422 swap, where the user had toggled
            // widthMode before saving.
            this._syncMaxWidthVisibility();
        }
        // Seed the column-width inputs from the stored CSV (or an equal split
        // for display when none is set yet) and paint the running total.
        this._initWidths();
    }

    disconnect() {
        // The sidebar is remounted after every column gesture: save what was
        // typed rather than drop it.
        for (const input of this.columnLabelTargets) {
            if (this._labelTimers?.has(input)) this._saveColumnLabel(input);
        }
        if (this.hasFormTarget) {
            this.formTarget.removeEventListener('submit', this._onSubmit);
            this.formTarget.removeEventListener('change', this._onChange);
        }
    }

    /**
     * The maxWidth row is only meaningful when the section is centered — the
     * decorator ignores it in "full" mode.
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

    // ---------- Columns: add, remove, label ----------

    // Add and remove are structural, so cb-builder runs them through its
    // mutation queue; the label is a value, saved from here.
    columnLabelTargetConnected(input) {
        input.dataset.cbSavedLabel = input.value;
    }

    addColumn() {
        this._requestColumnOp('cb:column:add-requested', {});
    }

    removeColumn(event) {
        const columnId = parseInt(event.currentTarget.dataset.cbColumnId, 10);
        if (!Number.isFinite(columnId)) return;
        this._requestColumnOp('cb:column:delete-requested', { columnId });
    }

    _requestColumnOp(name, detail) {
        const editor = this.element.querySelector('.cb-columns-editor');
        this.element.dispatchEvent(new CustomEvent(name, {
            bubbles: true,
            detail: {
                sectionId: this.sectionIdValue,
                ...detail,
                // Refusal reasons, already translated by the sidebar.
                messages: {
                    last_column: editor?.dataset.i18nCbColumnsLast,
                    too_many_columns: editor?.dataset.i18nCbColumnsTooMany,
                },
            },
        }));
    }

    onColumnLabelInput(event) {
        const input = event.currentTarget;
        this._labelTimers ??= new Map();
        clearTimeout(this._labelTimers.get(input));
        this._labelTimers.set(input, setTimeout(
            () => this._saveColumnLabel(input),
            this.constructor.COLUMN_LABEL_DEBOUNCE_MS,
        ));
    }

    commitColumnLabel(event) {
        this._saveColumnLabel(event.currentTarget);
    }

    async _saveColumnLabel(input) {
        clearTimeout(this._labelTimers?.get(input));
        this._labelTimers?.delete(input);

        const label = input.value;
        if (input.dataset.cbSavedLabel === label) return;
        const columnId = input.dataset.cbColumnId;
        const base = this.element.closest('[data-cb-api-base]')?.dataset.cbApiBase ?? '/_content-blocks';
        const csrfToken = this.element.closest('[data-cb-csrf-token]')?.dataset.cbCsrfToken || '';
        // Claimed before the request, so a change event right behind the
        // debounced save does not post the same value twice.
        const previous = input.dataset.cbSavedLabel;
        input.dataset.cbSavedLabel = label;

        let response;
        try {
            response = await fetch(`${base}/column/${columnId}/settings`, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
                body: JSON.stringify({ label }),
            });
        } catch (e) {
            console.error('[cb-section-settings-form] column label save failed', e);
            response = null;
        }

        if (!response?.ok) {
            input.dataset.cbSavedLabel = previous ?? '';
            this._dispatchSaveError();
            return;
        }

        // Same event as the form: the builder patches the tab bar in place.
        this.element.dispatchEvent(new CustomEvent('cb:section:saved', {
            bubbles: true,
            detail: { sectionId: this.sectionIdValue },
        }));
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
     * Highlights the matching preset, or reveals the free inputs. An empty
     * value highlights the equal preset, which is what the columns render as.
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

    /** Writes the canonical value; cb-autosave does the rest. */
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

        let response;
        try {
            response = await fetch(this.formTarget.action, {
                method: 'POST',
                body: formData,
                credentials: 'same-origin',
                headers: { 'X-CSRF-Token': csrfToken },
            });
        } catch (e) {
            // Network failure — surface it instead of dying silently.
            console.error('[cb-section-settings-form] save failed', e);
            this._dispatchSaveError();
            return;
        }

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
        this._dispatchSaveError();
    }

    /**
     * cb-autosave resets its dirty baseline and cb-builder raises the banner.
     *
     * @see docs/internals/frontend.md#live-component-failures-need-two-hooks
     */
    _dispatchSaveError() {
        this.element.dispatchEvent(new CustomEvent('cb:save:error', { bubbles: true }));
    }
}
