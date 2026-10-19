/**
 * Which locales Publish and Discard cover, asked in a dialog when a bundle
 * keeps locale drafts (i18n) and some are pending.
 *
 * @see docs/internals/publishing.md#choosing-the-locales-from-the-builder
 */
export default class PublishScope {
    /**
     * `null`: nothing to ask, the call covers every locale as it always did.
     * `false`: cancelled, or the scope could not be read. Else the codes.
     */
    async _askPublishScope(action) {
        if (!this.hasPublishScopeDialogTarget) return null;
        const scope = await this._jsonRequest(
            'GET', `${this._apiBase}/area/${this.areaIdValue}/publish-scope`,
        );
        if (scope === null) return false;
        const locales = Array.isArray(scope.locales) ? scope.locales : [];
        if (locales.length === 0) return null;

        return this._openPublishScope(action, locales, Boolean(scope.hasOwnDraft));
    }

    _openPublishScope(action, locales, hasOwnDraft) {
        const dialog = this.publishScopeDialogTarget;
        let strings = {};
        try {
            strings = JSON.parse(dialog.dataset.cbPublishScopeStrings || '{}');
        } catch {
            // The English fallbacks below still make a usable dialog.
        }
        const s = (key, fallback) => strings[key] || fallback;
        const part = (name) => dialog.querySelector(`.cb-publish-scope__${name}`);
        const publishing = action === 'publish';

        part('title').textContent = publishing
            ? s('publish_title', 'Publish the page')
            : s('discard_title', 'Revert to the published version');
        part('intro').textContent = publishing
            ? s('publish_intro', 'Translations are waiting. An unticked language keeps its draft.')
            : (hasOwnDraft
                ? s('discard_intro', 'Every unpublished change will be lost. Ticked languages lose their drafts too.')
                : s('discard_intro_locales', 'The ticked languages lose their drafts.'));
        // The shared layout matters only when the page itself changes.
        const note = part('note');
        note.textContent = s('layout_note', '');
        note.hidden = !(publishing && hasOwnDraft) || note.textContent === '';

        const list = part('list');
        list.replaceChildren(...locales.map((locale) => {
            const input = document.createElement('input');
            input.type = 'checkbox';
            input.value = String(locale.code);
            input.checked = true;
            const name = document.createElement('span');
            name.className = 'cb-publish-scope__name';
            name.textContent = String(locale.label || locale.code);
            const code = document.createElement('span');
            code.className = 'cb-publish-scope__code';
            code.textContent = String(locale.code).toUpperCase();
            const label = document.createElement('label');
            label.className = 'cb-publish-scope__locale';
            label.append(input, name, code);
            const item = document.createElement('li');
            item.append(label);

            return item;
        }));

        const confirm = part('confirm');
        const cancel = part('cancel');
        const hint = part('hint');
        confirm.textContent = publishing ? s('publish_confirm', 'Publish') : s('discard_confirm', 'Revert');
        confirm.classList.toggle('cb-publish-scope__confirm--danger', !publishing);
        const checked = () => [...list.querySelectorAll('input:checked')].map((i) => i.value);
        // With no page draft and no language ticked, the call would do nothing.
        const sync = () => {
            const idle = !hasOwnDraft && checked().length === 0;
            confirm.disabled = idle;
            hint.hidden = !idle;
        };
        sync();

        return new Promise((resolve) => {
            const onConfirm = () => dialog.close('confirm');
            const onCancel = () => dialog.close('cancel');
            const onClose = () => {
                list.removeEventListener('change', sync);
                confirm.removeEventListener('click', onConfirm);
                cancel.removeEventListener('click', onCancel);
                dialog.removeEventListener('close', onClose);
                resolve(dialog.returnValue === 'confirm' ? checked() : false);
            };
            list.addEventListener('change', sync);
            confirm.addEventListener('click', onConfirm);
            cancel.addEventListener('click', onCancel);
            dialog.addEventListener('close', onClose);
            dialog.returnValue = '';
            dialog.showModal();
        });
    }
}
