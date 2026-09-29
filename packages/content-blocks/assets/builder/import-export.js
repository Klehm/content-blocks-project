import { TransferDialog } from '../transfer/transfer-dialog.js';

/**
 * Opens the Import / Export dialog, whose logic lives in `transfer/`.
 *
 * @see docs/internals/frontend.md#the-builder-controller-is-split-by-feature
 * @see docs/internals/frontend.md#the-import-export-dialog
 */
export default class ImportExport {
    /** Action: the Actions menu entry. The dialog is built on first use. */
    openImportExport(event) {
        if (event) event.preventDefault();
        if (!this.hasTransferDialogTarget) return;
        this.closeActions();
        this._transfer ??= new TransferDialog(this.transferDialogTarget, {
            exportUrl: `${this._apiBase}/area/${this.areaIdValue}/export`,
            request: (path, init = {}) => this._transferRequest(path, init),
            onImported: (result) => {
                this._replacePickerLoaded = false;
                this._applyDraftState(result?.hasUnpublishedChanges ?? true);
                this.reload();
            },
        });
        this._transfer.open();
    }

    async _transferRequest(path, init) {
        const response = await fetch(`${this._apiBase}/area/${this.areaIdValue}${path}`, {
            credentials: 'same-origin',
            ...init,
            headers: {
                Accept: 'application/json',
                'X-CSRF-Token': this.element.dataset.cbCsrfToken || '',
                ...(init.headers ?? {}),
            },
        });
        if (this.constructor.isSessionLoss(response)) this._onSessionExpired();
        return response;
    }
}
