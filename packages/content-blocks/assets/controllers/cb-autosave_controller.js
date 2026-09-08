import { Controller } from '@hotwired/stimulus';

/**
 * Auto-save for the sidebar forms: `input` debounces, `change` and `focusout`
 * save at once. Both form kinds are driven through one in-form save button.
 *
 * @see docs/internals/forms.md#why-collection-reorder-and-duplicate-flush
 */
export default class extends Controller {
    static values = {
        /** Debounce window applied to `input` events, in ms. */
        debounce: { type: Number, default: 250 },
    };

    connect() {
        this._onInput = this._onInput.bind(this);
        this._onChange = this._onChange.bind(this);
        this._onFocusOut = this._onFocusOut.bind(this);
        this._onKeydown = this._onKeydown.bind(this);
        this._onSaveError = this._onSaveError.bind(this);
        this.element.addEventListener('input', this._onInput);
        this.element.addEventListener('change', this._onChange);
        this.element.addEventListener('focusout', this._onFocusOut);
        this.element.addEventListener('keydown', this._onKeydown);
        this.element.addEventListener('cb:save:error', this._onSaveError);
        // Snapshot the initial form state so the first save is only
        // issued when something has actually changed (see _saveNow).
        this._lastSerialized = this._serializeForm();

        // A live re-render emits no field events, so the listeners above
        // miss it. _saveNow()'s serialized compare makes this loop-free.
        this._observer = new MutationObserver(() => this._onMutation());
        const form = this.element.querySelector('form') ?? this.element;
        this._observer.observe(form, { childList: true, subtree: true });
    }

    disconnect() {
        this.element.removeEventListener('input', this._onInput);
        this.element.removeEventListener('change', this._onChange);
        this.element.removeEventListener('focusout', this._onFocusOut);
        this.element.removeEventListener('keydown', this._onKeydown);
        this.element.removeEventListener('cb:save:error', this._onSaveError);
        clearTimeout(this._timer);
        clearTimeout(this._mutationTimer);
        this._observer?.disconnect();
    }

    _onInput(event) {
        if (!this._isFormField(event.target)) return;
        this._scheduleSave();
    }

    _onChange(event) {
        if (!this._isFormField(event.target)) return;
        this._saveNow();
    }

    /**
     * _saveNow() bumped the baseline *before* the POST, so without this reset
     * the failed values would read as already saved and never be re-sent.
     *
     * @see docs/internals/frontend.md#live-component-failures-need-two-hooks
     */
    _onSaveError() {
        this._lastSerialized = null;
    }

    _onFocusOut(event) {
        if (!this._isFormField(event.target)) return;
        // Mostly catches a click completely outside the form; inside it,
        // `change` already fires for text inputs.
        this._saveNow();
    }

    /**
     * Enter would submit the surrounding form to its action URL, so it saves
     * instead. Multi-line targets keep the default, to type newlines.
     */
    _onKeydown(event) {
        if (event.key !== 'Enter') return;
        if (this._isMultiline(event.target)) return;
        event.preventDefault();
        this._saveNow();
    }

    _isMultiline(target) {
        if (!(target instanceof HTMLElement)) return false;
        if (target.tagName === 'TEXTAREA') return true;
        if (target.isContentEditable) return true;
        return target.closest('[contenteditable="true"]') !== null;
    }

    _scheduleSave() {
        clearTimeout(this._timer);
        this._timer = setTimeout(() => this._saveNow(), this.debounceValue);
    }

    /**
     * Debounces and reconciles: _saveNow() only saves on a real change, so the
     * morph the save itself causes is a no-op.
     */
    _onMutation() {
        if (this._saving) return;
        clearTimeout(this._mutationTimer);
        this._mutationTimer = setTimeout(() => this._saveNow(), this.debounceValue);
    }

    _saveNow() {
        clearTimeout(this._timer);
        if (this._saving) return; // Re-entrancy guard, see below.
        const btn = this.element.querySelector('[data-cb-sidebar-save]');
        if (!btn) return;

        // One logical edit fires both the debounce and a focusout, so
        // comparing serialized state collapses the pair into one save.
        const current = this._serializeForm();
        if (current === this._lastSerialized) return;
        this._lastSerialized = current;

        // Live syncs its LiveProp on `change`, so without this a mid-typing
        // save POSTs the stale value. `_saving` stops it re-entering.
        this._saving = true;
        try {
            const active = document.activeElement;
            // Never on a file input: it would re-trigger cb-file-upload,
            // which re-uploads under a fresh name and saves again, forever.
            const isFileInput = active instanceof HTMLInputElement && active.type === 'file';
            if (active instanceof HTMLElement && this.element.contains(active)
                && this._isFormField(active) && !isFileInput) {
                active.dispatchEvent(new Event('change', { bubbles: true }));
            }
            btn.click();
        } finally {
            this._saving = false;
        }
    }

    /**
     * Sorted, so the order cannot shift. `[linked]` toggles are dropped:
     * cb-spacing-link engages them on connect and would dirty the baseline.
     */
    _serializeForm() {
        const form = this.element.querySelector('form');
        if (!form) return '';
        try {
            const params = new URLSearchParams(new FormData(form));
            for (const key of [...params.keys()]) {
                if (key.endsWith('[linked]')) params.delete(key);
            }
            params.sort();
            return params.toString();
        } catch (_) {
            return '';
        }
    }

    _isFormField(target) {
        if (!(target instanceof HTMLElement)) return false;
        return target.matches('input, textarea, select, [contenteditable], [contenteditable=""], [contenteditable="true"]');
    }
}
