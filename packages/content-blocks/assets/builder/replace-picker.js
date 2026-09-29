/**
 * The "Insert content" picker: search another area, then overwrite this
 * one's draft with its sections.
 *
 * @see docs/internals/frontend.md#the-builder-controller-is-split-by-feature
 */
export default class ReplacePicker {
    /** Debounce window (ms) on the replace-picker search input. */
    static REPLACE_PICKER_DEBOUNCE_MS = 250;

    /** Confirm prompt shown before applying a destructive replace. */
    static REPLACE_PICKER_CONFIRM_FALLBACK =
        'Are you sure you want to overwrite the current content with the selected one?';

    /**
     * First open loads the candidate list; later opens re-use the cache, so
     * hopping in and out does not flash the network.
     */
    async openReplacePicker(event) {
        if (event) event.preventDefault();
        if (!this.hasReplacePickerTarget) return;
        // Focus goes back where it came from on close.
        this._replacePickerOpener = document.activeElement;
        this.closeActions();
        this.replacePickerTarget.hidden = false;
        this._setBackdrop(true);

        if (this.hasReplacePickerSearchTarget) {
            // Don't clobber the user's last query when reopening — preserve
            // the filter so iterative searches feel continuous.
            this.replacePickerSearchTarget.focus({ preventScroll: true });
        }

        // First open OR a stale list (after a successful replace we reset
        // the cache so the next open shows fresh candidates).
        if (!this._replacePickerLoaded) {
            await this._loadReplaceCandidates('');
            this._replacePickerLoaded = true;
        }
    }

    /** Action: × button on the picker header. */
    closeReplacePicker(event) {
        if (event) event.preventDefault();
        if (!this.hasReplacePickerTarget) return;
        this.replacePickerTarget.hidden = true;
        this._setBackdrop(false);
        const opener = this._replacePickerOpener;
        this._replacePickerOpener = null;
        if (opener?.isConnected && typeof opener.focus === 'function') {
            opener.focus({ preventScroll: true });
        }
    }

    /** Action: keydown on the picker. It is modal, so Tab stays inside. */
    onReplacePickerKeydown(event) {
        if (event.key !== 'Tab' || !this.hasReplacePickerTarget) return;
        this.constructor.trapTab(this.replacePickerTarget, event);
    }

    /** Action: input event on the picker's search field (debounced). */
    onReplacePickerSearch(event) {
        const value = event?.target?.value ?? '';
        clearTimeout(this._replacePickerSearchTimer);
        this._replacePickerSearchTimer = setTimeout(() => {
            this._loadReplaceCandidates(value);
        }, this.constructor.REPLACE_PICKER_DEBOUNCE_MS);
    }

    async _loadReplaceCandidates(filter) {
        if (!this.hasReplacePickerListTarget) return;
        this._setReplacePickerStatus(this._t('cb.builder.replace.loading', 'Loading…'));
        this.replacePickerListTarget.innerHTML = '';

        const params = new URLSearchParams();
        if (filter) params.set('q', filter);
        const qs = params.toString();
        const url = `${this._apiBase}/area/${this.areaIdValue}/replace-candidates${qs ? `?${qs}` : ''}`;

        let payload;
        try {
            const response = await fetch(url, {
                credentials: 'same-origin',
                headers: { Accept: 'application/json' },
            });
            if (this.constructor.isSessionLoss(response)) {
                this._onSessionExpired();
            }
            if (!response.ok) throw new Error(`status ${response.status}`);
            payload = await response.json();
        } catch (e) {
            console.error('[cb-builder] replace candidates failed', e);
            this._setReplacePickerStatus(this._t('cb.builder.replace.error', 'Failed to load.'));
            return;
        }

        this._renderReplaceCandidates(payload, filter);
    }

    _renderReplaceCandidates(payload, filter) {
        const items = Array.isArray(payload?.items) ? payload.items : [];
        const list = this.replacePickerListTarget;
        list.innerHTML = '';

        if (items.length === 0) {
            this._setReplacePickerStatus(filter
                ? this._t('cb.builder.replace.empty_filtered', 'No results for this search')
                : this._t('cb.builder.replace.empty', 'No content available'),
            );
            return;
        }
        this._setReplacePickerStatus('');

        for (const item of items) {
            const li = document.createElement('li');
            li.className = 'cb-replace-picker__item';
            li.setAttribute('role', 'option');
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'cb-replace-picker__item-btn';
            btn.dataset.cbReplaceSourceId = String(item.id ?? '');
            btn.textContent = item.label ?? `#${item.id}`;
            btn.addEventListener('click', () => this._confirmAndReplace(item));
            li.appendChild(btn);
            list.appendChild(li);
        }
    }

    async _confirmAndReplace(item) {
        const confirmText = this._t(
            'cb.builder.replace.confirm',
            this.constructor.REPLACE_PICKER_CONFIRM_FALLBACK,
        );
        if (!window.confirm(confirmText)) return;

        const result = await this._jsonRequest(
            'POST',
            `${this._apiBase}/area/${this.areaIdValue}/replace-with/${item.id}`,
        );
        if (result === null) return;
        // The target's updatedAt just changed, so a sticky cache would
        // lie on the next open.
        this._replacePickerLoaded = false;
        this.closeReplacePicker();
        this._applyDraftState(result.hasUnpublishedChanges ?? true);
        this.reload();
    }

    _setReplacePickerStatus(text) {
        if (!this.hasReplacePickerStatusTarget) return;
        this.replacePickerStatusTarget.textContent = text;
    }
}
