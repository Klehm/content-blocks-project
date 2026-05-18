import { Controller } from '@hotwired/stimulus';

/**
 * Auto-save trigger for forms rendered inside the builder sidebar.
 *
 * Attached to the root element of a block edit form or a section
 * settings form. It listens to user edits and clicks an in-form
 * `[data-cb-sidebar-save]` button to persist the change — block forms
 * route that click through Live Component's `live#action save`, section
 * forms through the cb-section-settings-form controller's submit
 * interceptor, so the trigger contract stays uniform across both.
 *
 * Strategy:
 *  - `input` event   → debounce (default 250 ms) then save. Catches
 *                      ongoing typing in text inputs.
 *  - `change` event  → save immediately. Fired by the browser after a
 *                      commit (select / checkbox / radio / file pickers,
 *                      and on blur for text inputs).
 *  - `focusout`      → save immediately as a safety net for fields
 *                      that don't reliably emit `change` (e.g. some
 *                      contenteditable widgets).
 *
 * Before triggering the save click, we synthesize a `change` event on
 * the still-focused field. Live Component form fields sync their
 * value into the LiveProp on `change`, so without this dispatch a
 * mid-typing debounce save would POST the stale (pre-edit) value.
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
        this.element.addEventListener('input', this._onInput);
        this.element.addEventListener('change', this._onChange);
        this.element.addEventListener('focusout', this._onFocusOut);
        this.element.addEventListener('keydown', this._onKeydown);
    }

    disconnect() {
        this.element.removeEventListener('input', this._onInput);
        this.element.removeEventListener('change', this._onChange);
        this.element.removeEventListener('focusout', this._onFocusOut);
        this.element.removeEventListener('keydown', this._onKeydown);
        clearTimeout(this._timer);
    }

    _onInput(event) {
        if (!this._isFormField(event.target)) return;
        this._scheduleSave();
    }

    _onChange(event) {
        if (!this._isFormField(event.target)) return;
        this._saveNow();
    }

    _onFocusOut(event) {
        if (!this._isFormField(event.target)) return;
        // focusout fires before the related target gets focus, so even
        // when moving between two fields in the same form we still get
        // a save. Inside the form change always fires for text inputs;
        // this listener mostly catches the "user clicks completely
        // outside the form" case.
        this._saveNow();
    }

    /**
     * Pressing Enter inside a single-line input would submit the
     * surrounding <form> to its `action` URL — for the section form
     * that's a real POST, for the block Live form it would navigate the
     * iframe. Intercept Enter and trigger our own save instead so the
     * flow stays consistent with the auto-save model. Multi-line
     * targets (textarea / contenteditable) keep their default
     * behavior so users can type newlines.
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

    _saveNow() {
        clearTimeout(this._timer);
        if (this._saving) return; // Re-entrancy guard, see below.
        const btn = this.element.querySelector('[data-cb-sidebar-save]');
        if (!btn) return;

        // Flush the currently-focused field so Live Component model
        // bindings observe the latest value. Dispatching `change`
        // (rather than blurring) keeps the user's cursor where it was,
        // which matters for input-debounce saves that happen mid-edit.
        // The `_saving` flag suppresses our own change/focusout
        // handlers while we synthesize the event — otherwise the
        // dispatch would re-enter _saveNow() and loop forever.
        this._saving = true;
        try {
            const active = document.activeElement;
            if (active instanceof HTMLElement && this.element.contains(active) && this._isFormField(active)) {
                active.dispatchEvent(new Event('change', { bubbles: true }));
            }
            btn.click();
        } finally {
            this._saving = false;
        }
    }

    _isFormField(target) {
        if (!(target instanceof HTMLElement)) return false;
        return target.matches('input, textarea, select, [contenteditable], [contenteditable=""], [contenteditable="true"]');
    }
}
