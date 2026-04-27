import { Controller } from '@hotwired/stimulus';

/**
 * Keyboard shortcuts inside a block edit form:
 * - Enter   → click the Save button (skipped in textarea / contenteditable
 *             so multi-line editors keep their default behavior)
 * - Escape  → click the Cancel button
 *
 * Also blocks the default Enter-submit on the surrounding <form>, which
 * would otherwise post to the host page and break the Live Component flow.
 */
export default class extends Controller {
    static targets = ['saveBtn', 'cancelBtn'];

    connect() {
        this._onKeydown = this._onKeydown.bind(this);
        this.element.addEventListener('keydown', this._onKeydown);
    }

    disconnect() {
        this.element.removeEventListener('keydown', this._onKeydown);
    }

    _onKeydown(event) {
        if (event.key === 'Enter' && !this._isMultilineTarget(event.target)) {
            event.preventDefault();
            if (this.hasSaveBtnTarget) {
                this.saveBtnTarget.click();
            }
            return;
        }

        if (event.key === 'Escape') {
            event.preventDefault();
            if (this.hasCancelBtnTarget) {
                this.cancelBtnTarget.click();
            }
        }
    }

    _isMultilineTarget(target) {
        if (!(target instanceof HTMLElement)) return false;
        if (target.tagName === 'TEXTAREA') return true;
        if (target.isContentEditable) return true;
        return target.closest('[contenteditable="true"]') !== null;
    }
}
