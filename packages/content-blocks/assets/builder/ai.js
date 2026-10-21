/**
 * The AI actions: a menu beside a text or `alt` field that proposes a new
 * value, and a dialog that generates sections into the draft.
 *
 * @see docs/internals/ai.md#the-builder-side
 * @see docs/internals/frontend.md#the-builder-controller-is-split-by-feature
 */
export default class Ai {
    /** The actions the server drew, from `data-cb-ai-actions`. */
    get _aiActions() {
        if (!this.hasAiTarget) return [];

        return (this.aiTarget.dataset.cbAiActions ?? '').split(' ').filter(Boolean);
    }

    _aiString(key, fallback = '') {
        if (this._aiStrings === undefined) {
            try {
                this._aiStrings = JSON.parse(this.aiTarget?.dataset.cbAiStrings ?? '{}') ?? {};
            } catch {
                this._aiStrings = {};
            }
        }

        return this._aiStrings[key] || fallback;
    }

    _aiTones() {
        try {
            const tones = JSON.parse(this.aiTarget?.dataset.cbAiTones ?? '[]');

            return Array.isArray(tones) ? tones : [];
        } catch {
            return [];
        }
    }

    // ---------- Field actions ----------

    /** Action: the ✦ button beside a field. Opens its menu. */
    openAiField(event) {
        event?.preventDefault();
        if (!this.hasAiPopoverTarget) return;
        const { kind, field, image } = event?.params ?? {};
        const input = field ? document.getElementById(field) : null;
        if (!input) return;

        this._aiField = { kind, input, imageId: image ?? null, trigger: event.currentTarget };
        this._renderAiMenu();
        // Under the field's row, so the text being reworked stays in view.
        this._showAiPopover(input.closest('.cb-form-row') ?? event.currentTarget);
    }

    closeAiPopover() {
        if (!this.hasAiPopoverTarget || this.aiPopoverTarget.hidden) return;
        this.aiPopoverTarget.hidden = true;
        this.aiPopoverTarget.replaceChildren();
        // Bumped so an answer arriving after a close is dropped.
        this._aiRequestSeq = (this._aiRequestSeq ?? 0) + 1;
        const trigger = this._aiField?.trigger;
        this._aiField = null;
        if (trigger?.isConnected) trigger.focus({ preventScroll: true });
    }

    /** Action: keydown inside the popover. Escape closes it. */
    onAiPopoverKeydown(event) {
        if (event.key !== 'Escape') return;
        event.preventDefault();
        event.stopPropagation();
        this.closeAiPopover();
    }

    _renderAiMenu() {
        const { kind } = this._aiField;
        const actions = this._aiActions;
        const items = [];
        if (kind === 'alt') {
            if (actions.includes('alt_text')) items.push({ label: this._aiString('alt_text', 'Describe the image'), action: 'alt_text' });
        } else {
            if (actions.includes('rewrite')) items.push({ label: this._aiString('rewrite', 'Rewrite'), action: 'rewrite' });
            if (actions.includes('shorten')) items.push({ label: this._aiString('shorten', 'Shorten'), action: 'shorten' });
            if (actions.includes('tone')) {
                for (const tone of this._aiTones()) {
                    const label = this._aiString('tone', 'Tone: %tone%').replace('%tone%', tone.label);
                    items.push({ label, action: 'tone', tone: tone.value });
                }
            }
        }

        const list = document.createElement('div');
        list.className = 'cb-ai-popover__menu';
        list.setAttribute('role', 'menu');
        for (const item of items) {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'cb-ai-popover__item';
            button.setAttribute('role', 'menuitem');
            button.textContent = item.label;
            button.dataset.cbAiAction = item.action;
            if (item.tone) button.dataset.cbAiTone = item.tone;
            button.addEventListener('click', () => this._runAiField(item.action, item.tone ?? null));
            list.append(button);
        }
        this.aiPopoverTarget.replaceChildren(list);
    }

    /** Under the anchor, right-aligned with it, kept inside the shell. */
    _showAiPopover(anchor) {
        const popover = this.aiPopoverTarget;
        popover.hidden = false;
        const shell = this.element.getBoundingClientRect();
        const rect = anchor.getBoundingClientRect();
        const width = popover.offsetWidth || 280;
        const left = Math.max(8, Math.min(rect.right - shell.left - width, shell.width - width - 8));
        popover.style.left = `${left}px`;
        popover.style.top = `${rect.bottom - shell.top + 6}px`;
        popover.querySelector('button')?.focus({ preventScroll: true });
    }

    async _runAiField(action, tone) {
        const field = this._aiField;
        if (!field) return;

        let url;
        let body;
        const { blockId } = this._selectionIds();
        if (action === 'alt_text') {
            const src = field.imageId ? document.getElementById(field.imageId)?.value?.trim() : '';
            if (!src) {
                this._renderAiMessage(this._aiString('no_image', 'Choose an image first'));
                return;
            }
            url = `${this._apiBase}/area/${this.areaIdValue}/ai/alt-text`;
            body = { src, ...(blockId ? { blockId } : {}) };
        } else {
            const text = field.input.value ?? '';
            if (text.trim() === '') {
                this._renderAiMessage(this._aiString('empty_field', 'Write something first'));
                return;
            }
            url = `${this._apiBase}/area/${this.areaIdValue}/ai/text`;
            body = {
                action,
                text,
                html: field.kind === 'html',
                ...(tone ? { tone } : {}),
                ...(blockId ? { blockId } : {}),
                field: this.constructor.aiFieldPath(field.input.name),
            };
        }

        const seq = (this._aiRequestSeq = (this._aiRequestSeq ?? 0) + 1);
        this._renderAiMessage(this._aiString('working', 'Writing…'), { busy: true });
        // Not through the mutation queue: nothing is written, and a slow
        // model must not hold up the editor's own saves.
        const result = await this._performJsonRequest('POST', url, body, { tolerate: [400, 404, 422, 502] });
        if (seq !== this._aiRequestSeq || !this._aiField) return;

        if (!result || result.error || typeof result.text !== 'string') {
            this._renderAiMessage(this._aiError(result?.error), {
                retry: () => this._runAiField(action, tone),
            });
            return;
        }
        this._renderAiProposal(result.text, () => this._runAiField(action, tone));
    }

    _renderAiProposal(text, retry) {
        const wrap = document.createElement('div');
        wrap.className = 'cb-ai-popover__proposal';
        const preview = document.createElement('div');
        preview.className = 'cb-ai-popover__text';
        // Shown as text even for an HTML field: the model's markup is not
        // trusted in the builder's own document.
        preview.textContent = text;
        wrap.append(preview, this._aiButtons([
            { label: this._aiString('apply', 'Replace'), primary: true, onClick: () => this._applyAiText(text) },
            { label: this._aiString('retry', 'Try again'), onClick: retry },
            { label: this._aiString('cancel', 'Cancel'), onClick: () => this.closeAiPopover() },
        ]));
        this.aiPopoverTarget.replaceChildren(wrap);
        this.aiPopoverTarget.querySelector('.cb-ai-popover__btn--primary')?.focus({ preventScroll: true });
    }

    _renderAiMessage(message, { busy = false, retry = null } = {}) {
        const wrap = document.createElement('div');
        wrap.className = 'cb-ai-popover__message';
        const p = document.createElement('p');
        p.textContent = message;
        p.setAttribute('role', 'status');
        wrap.append(p);
        if (!busy) {
            const buttons = [{ label: this._aiString('cancel', 'Cancel'), onClick: () => this.closeAiPopover() }];
            if (retry) buttons.unshift({ label: this._aiString('retry', 'Try again'), onClick: retry });
            wrap.append(this._aiButtons(buttons));
        }
        this.aiPopoverTarget.classList.toggle('cb-ai-popover--busy', busy);
        this.aiPopoverTarget.replaceChildren(wrap);
    }

    _aiButtons(specs) {
        const row = document.createElement('div');
        row.className = 'cb-ai-popover__actions';
        for (const spec of specs) {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = `cb-ai-popover__btn${spec.primary ? ' cb-ai-popover__btn--primary' : ''}`;
            button.textContent = spec.label;
            button.addEventListener('click', spec.onClick);
            row.append(button);
        }

        return row;
    }

    _applyAiText(text) {
        const input = this._aiField?.input;
        this.closeAiPopover();
        if (input?.isConnected) this.constructor.setFieldValue(input, text);
    }

    /**
     * `cb:field:set` first, for a rich-text editor owning the textarea; else
     * the value and the events the autosave and Live listen to.
     */
    static setFieldValue(input, value) {
        const handled = !input.dispatchEvent(new CustomEvent('cb:field:set', {
            detail: { value },
            cancelable: true,
        }));
        if (handled) return;
        input.value = value;
        input.dispatchEvent(new Event('input', { bubbles: true }));
        input.dispatchEvent(new Event('change', { bubbles: true }));
    }

    /** `content_block[items][0][title]` → `items.0.title`. */
    static aiFieldPath(name) {
        const parts = [...String(name ?? '').matchAll(/\[([^\]]*)\]/g)].map((m) => m[1]);

        return parts.join('.');
    }

    _aiError(code) {
        const known = ['provider_failed', 'empty', 'sections_locked', 'no_target', 'too_large', 'disabled', 'invalid', 'unreadable'];

        return this._aiString(`error_${known.includes(code) ? code : 'provider_failed'}`, 'The AI service did not answer. Try again.');
    }

    // ---------- Generation dialog ----------

    /** Action: the topbar's Generate button. */
    openAiDialog(event) {
        event?.preventDefault();
        this._openAiDialog(null);
    }

    /** From the ✦ on a section toolbar in the preview: that section. */
    openAiSectionEdit(sectionId) {
        if (!Number.isFinite(sectionId)) return;
        this._openAiDialog('edit_section', sectionId);
    }

    _openAiDialog(scope, sectionId = null) {
        if (!this.hasAiDialogTarget) return;
        this.closeActions?.();
        this._aiTargetSectionId = sectionId;
        const radio = scope ? this.aiDialogTarget.querySelector(`input[name="scope"][value="${scope}"]`) : null;
        if (radio) radio.checked = true;
        this._setAiBusy(false);
        this.onAiScopeChange();
        this.aiDialogTarget.showModal();
        this.aiPromptTarget?.focus();
    }

    closeAiDialog(event) {
        event?.preventDefault();
        if (this.hasAiDialogTarget && this.aiDialogTarget.open) this.aiDialogTarget.close();
        this._aiTargetSectionId = null;
    }

    /** Action: the scope radios. A revision says what it will replace. */
    onAiScopeChange() {
        const hint = {
            edit_section: this._aiTarget() ? this._aiString('edit_hint') : this._aiString('needs_section'),
            edit_page: this._aiString('edit_page_hint'),
        }[this._aiScope()];
        this._setAiStatus(hint ?? '');
    }

    _aiScope() {
        const checked = this.aiDialogTarget?.querySelector('input[name="scope"]:checked');

        return checked?.value ?? ['add', 'edit_section', 'edit_page'].find((a) => this._aiActions.includes(a));
    }

    /** The section a request is about: the toolbar one, else the selection. */
    _aiTarget() {
        if (Number.isFinite(this._aiTargetSectionId)) return { targetSectionId: this._aiTargetSectionId };
        const { blockId, sectionId } = this._selectionIds();
        if (sectionId) return { targetSectionId: sectionId };

        return blockId ? { targetBlockId: blockId } : null;
    }

    /** Sent when the preview is ready, so its section toolbar can offer ✦. */
    _announceAiToPreview() {
        if (!this._aiActions.includes('edit_section')) return;
        this._postToPreview({ type: 'cb:ai:capabilities', editSection: true });
    }

    async submitAiDialog(event) {
        event?.preventDefault();
        if (this._aiGenerating) return;
        const prompt = this.aiPromptTarget?.value?.trim() ?? '';
        if (prompt === '') {
            this.aiPromptTarget?.focus();
            return;
        }

        const action = this._aiScope();
        const target = action === 'add' || action === 'edit_section' ? this._aiTarget() : null;
        if (action === 'edit_section' && !target) {
            this._setAiStatus(this._aiString('needs_section', 'Select the section to change first'));
            return;
        }

        this._setAiBusy(true);
        this._setAiStatus(this._aiString('generating', 'Generating…'));
        const result = await this._jsonRequest(
            'POST',
            `${this._apiBase}/area/${this.areaIdValue}/ai/content`,
            {
                action,
                prompt,
                ...(target ?? {}),
            },
            { tolerate: [400, 404, 409, 422, 502] },
        );
        this._setAiBusy(false);

        if (result === null) {
            this._setAiStatus(this._aiError('provider_failed'));
            return;
        }
        if (result.error) {
            this._setAiStatus(this._aiError(result.error));
            return;
        }

        this.aiPromptTarget.value = '';
        this.closeAiDialog();
        const warning = this._restoreWarning(result, {
            skipped: ['cb.ai.content.skipped_blocks', this._aiString('skipped_blocks', 'Generated — %count% block(s) skipped: %types%')],
            unknown: ['cb.ai.content.dropped_fields', this._aiString('dropped_fields', 'Generated, but some fields were reset on: %types%')],
            fieldsKey: 'droppedFields',
        });
        this._notify(warning ?? this._aiString('done', 'Generated as a draft — review it, then Publish'));

        const first = Array.isArray(result.sectionIds) ? result.sectionIds[0] : null;
        if (first) this._scrollPreviewTo(first);
        this._afterStructuralOp();
    }

    _setAiStatus(message) {
        if (this.hasAiStatusTarget) this.aiStatusTarget.textContent = message;
    }

    _setAiBusy(busy) {
        this._aiGenerating = busy;
        if (this.hasAiSubmitTarget) this.aiSubmitTarget.disabled = busy;
        if (this.hasAiPromptTarget) this.aiPromptTarget.readOnly = busy;
        this.aiDialogTarget?.classList.toggle('cb-ai-dialog--busy', busy);
    }
}
