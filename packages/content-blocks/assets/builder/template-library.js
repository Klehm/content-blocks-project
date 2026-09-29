/**
 * The section library in the empty sidebar: save a section to it, list,
 * search, insert and delete templates.
 *
 * @see docs/internals/frontend.md#the-builder-controller-is-split-by-feature
 * @see docs/internals/section-templates.md
 */
export default class TemplateLibrary {
    /** Prompt asking for a name when saving a section to the library. */
    static TEMPLATE_NAME_FALLBACK = 'Name this section template:';

    /** Confirm prompt shown before deleting a library template. */
    static TEMPLATE_DELETE_CONFIRM_FALLBACK =
        'Delete this section template? This cannot be undone.';

    /**
     * Saves a section into the global library. The name prompt mirrors the
     * confirm-based UX used elsewhere, so there is no extra dialog markup.
     */
    async _saveSectionAsTemplate(sectionId) {
        const id = parseInt(sectionId, 10);
        if (!Number.isFinite(id)) return;

        const raw = window.prompt(this._t('cb.builder.template.name_prompt', this.constructor.TEMPLATE_NAME_FALLBACK));
        if (raw === null) return; // cancelled
        const name = raw.trim();
        if (!name) return;

        const result = await this._jsonRequest(
            'POST',
            `${this._apiBase}/section/${id}/save-as-template`,
            { name },
        );
        if (result === null) return;
        // The library changed — drop the cache so the next paint re-fetches.
        this._templateItems = null;
        this._flashSaved();
        // The library is the one place that answers "did it save, and
        // under what name?", and where the next move starts.
        await this.openTemplatePicker();
    }

    /**
     * Also invoked from the iframe tray. First open loads the list; later ones
     * re-use the cache unless a save or delete invalidated it.
     */
    async openTemplatePicker(event) {
        if (event) event.preventDefault();
        this.closeActions();
        // The library lives in the empty sidebar, so opening it means
        // clearing the selection.
        this._resetSidebarToEmptyState();
        // Right when the user clicked away, wrong when they asked for the
        // library. Asking wins.
        if (this._isMobile()) {
            this._setSidebarCollapsed(false, { persist: false });
        }
        if (!this.hasTemplatePickerTarget) return;
        if (this.hasTemplatePickerSearchTarget) {
            this.templatePickerSearchTarget.focus({ preventScroll: true });
        }
        await this._showTemplates();
    }

    /**
     * Every return to the empty state rebuilds the sidebar DOM, so the list is
     * repainted each time — from cache, since clicking away is navigation.
     */
    async _showTemplates() {
        if (!this.hasTemplatePickerListTarget) return;
        if (this._templateItems === null || this._templateItems === undefined) {
            await this._loadTemplates(this._templatePickerFilter ?? '', 0, false);
            return;
        }
        if (this.hasTemplatePickerSearchTarget && this._templatePickerFilter) {
            this.templatePickerSearchTarget.value = this._templatePickerFilter;
        }
        this._paintTemplates();
    }

    /** Debounced input on the template picker's search field. */
    onTemplatePickerSearch(event) {
        const value = event?.target?.value ?? '';
        clearTimeout(this._templatePickerSearchTimer);
        this._templatePickerSearchTimer = setTimeout(() => {
            this._loadTemplates(value, 0, false);
        }, this.constructor.REPLACE_PICKER_DEBOUNCE_MS);
    }

    async _loadTemplates(filter, page = 0, append = false) {
        if (!this.hasTemplatePickerListTarget) return;
        this._templatePickerFilter = filter;
        if (!append) {
            this._templateItems = [];
            this.templatePickerListTarget.innerHTML = '';
            this._setTemplatePickerStatus(this._t('cb.builder.template.loading', 'Loading…'));
        }

        const params = new URLSearchParams();
        if (filter) params.set('q', filter);
        if (page > 0) params.set('page', String(page));
        const qs = params.toString();
        const url = `${this._apiBase}/area/${this.areaIdValue}/section-templates${qs ? `?${qs}` : ''}`;

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
            console.error('[cb-builder] section templates failed', e);
            this._setTemplatePickerStatus(this._t('cb.builder.template.error', 'Failed to load.'));
            return;
        }

        this._renderTemplates(payload, filter, append);
    }

    /**
     * Folds a page into the accumulated list, then repaints. The DOM is
     * disposable; `_templateItems` is what survives a sidebar rebuild.
     */
    _renderTemplates(payload, filter, append) {
        const items = Array.isArray(payload?.items) ? payload.items : [];
        this._templateItems = append ? [...(this._templateItems ?? []), ...items] : items;
        this._templateHasMore = payload?.hasMore === true;
        this._templatePage = payload?.page ?? 0;
        this._paintTemplates();
    }

    /** Renders `_templateItems`. Safe to call repeatedly. */
    _paintTemplates() {
        if (!this.hasTemplatePickerListTarget) return;
        const list = this.templatePickerListTarget;
        const filter = this._templatePickerFilter ?? '';
        const items = this._templateItems ?? [];
        list.innerHTML = '';

        if (items.length === 0) {
            this._setTemplatePickerStatus(filter
                ? this._t('cb.builder.template.empty_filtered', 'No templates match this search')
                : this._t('cb.builder.template.empty', 'No saved templates yet'),
            );
            return;
        }
        this._setTemplatePickerStatus('');

        for (const item of items) {
            list.appendChild(this._buildTemplateRow(item, filter));
        }

        if (this._templateHasMore) {
            const more = document.createElement('button');
            more.type = 'button';
            more.className = 'cb-template-picker__more';
            more.textContent = this._t('cb.builder.template.load_more', 'Load more');
            const nextPage = (this._templatePage ?? 0) + 1;
            more.addEventListener('click', () => this._loadTemplates(filter, nextPage, true));
            list.appendChild(more);
        }
    }

    _buildTemplateRow(item, filter) {
        const li = document.createElement('li');
        li.className = 'cb-template-picker__item';
        li.setAttribute('role', 'option');

        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'cb-template-picker__item-btn';

        // The poster is decorative — the name already labels the card — and
        // a payload with nothing drawable falls back to a plain named row.
        const poster = this._buildTemplatePoster(item.poster);
        if (poster) btn.appendChild(poster);

        const name = document.createElement('span');
        name.className = 'cb-template-picker__item-name';
        name.textContent = item.name ?? `#${item.id}`;
        btn.appendChild(name);

        const skipped = Array.isArray(item.skippedTypes) ? item.skippedTypes : [];

        if (item.insertable === false) {
            // Three distinct reasons, not interchangeable to whoever must
            // act on them. See section-templates.md
            btn.disabled = true;
            if (item.unreadableFormat) {
                btn.title = this._t(
                    'cb.builder.template.unreadable_format',
                    'Unavailable — saved by an incompatible version of the section library',
                );
            } else if (item.staleVersion) {
                btn.title = this._t(
                    'cb.builder.template.stale_version',
                    'Unavailable — saved under an older version of your content schema',
                );
            } else {
                btn.title = this._t(
                    'cb.builder.template.incompatible',
                    'Unavailable — none of its block types exist here: %types%',
                ).replace('%types%', skipped.join(', '));
            }
            li.classList.add('cb-template-picker__item--disabled');
        } else {
            // Partially usable templates stay clickable — the editor is warned
            // before the click rather than blocked from a useful insert.
            if (skipped.length > 0) {
                btn.title = this._t(
                    'cb.builder.template.partial',
                    '%count% block(s) will be skipped — missing type(s): %types%',
                ).replace('%count%', String(skipped.length)).replace('%types%', skipped.join(', '));
                li.classList.add('cb-template-picker__item--partial');
            }
            btn.addEventListener('click', () => this._confirmInsert(item));
        }
        li.appendChild(btn);

        if (item.canManage) {
            const del = document.createElement('button');
            del.type = 'button';
            del.className = 'cb-template-picker__delete';
            del.textContent = '🗑';
            del.title = this._t('cb.builder.template.delete', 'Delete template');
            del.setAttribute('aria-label', del.title);
            del.addEventListener('click', (e) => {
                e.stopPropagation();
                this._deleteTemplate(item, filter);
            });
            li.appendChild(del);
        }

        return li;
    }

    async _confirmInsert(item) {
        const result = await this._jsonRequest(
            'POST',
            `${this._apiBase}/area/${this.areaIdValue}/insert-template/${item.id}`,
        );
        if (result === null) return;

        // The insert succeeded, possibly minus a few blocks.
        const warning = this._restoreWarning(result, {
            skipped: ['cb.builder.template.skipped_blocks', 'Inserted — %count% block(s) skipped, missing type(s): %types%'],
            unknown: ['cb.builder.template.warnings', 'Inserted, but some stored fields no longer exist on: %types%'],
        });

        this._scrollPreviewTo(result.sectionId);
        this._afterStructuralOp();

        if (warning !== null) {
            // They share a panel, so mounting the form would blank the only
            // element carrying the warning.
            this._setTemplatePickerStatus(warning);
        } else if (result.sectionId) {
            this._mountSectionSettings(result.sectionId);
        }
    }

    async _deleteTemplate(item, filter) {
        const confirmText = this._t(
            'cb.builder.template.delete_confirm',
            this.constructor.TEMPLATE_DELETE_CONFIRM_FALLBACK,
        );
        if (!window.confirm(confirmText)) return;

        const result = await this._jsonRequest('DELETE', `${this._apiBase}/section-templates/${item.id}`);
        if (result === null) return;
        // From the top, so counts and pagination stay sane.
        await this._loadTemplates(filter ?? this._templatePickerFilter ?? '', 0, false);
    }

    _setTemplatePickerStatus(text) {
        if (!this.hasTemplatePickerStatusTarget) return;
        this.templatePickerStatusTarget.textContent = text;
    }
}
