/**
 * Translated strings, read off `data-i18n-*` attributes with an English
 * fallback, and the warning a restore reports.
 *
 * @see docs/internals/frontend.md#the-builder-controller-is-split-by-feature
 */
export default class Strings {
    /**
     * Reads precomputed strings off `data-i18n-*` attributes, falling back to
     * English — dependency-free, and still overridable by a host.
     */
    _t(key, fallback) {
        const attr = 'data-i18n-' + key.replace(/[._]/g, '-');
        const sources = [];
        if (this.hasReplacePickerTarget) sources.push(this.replacePickerTarget);
        if (this.hasTemplatePickerTarget) sources.push(this.templatePickerTarget);
        // Shell root: always present, carries topbar strings the pickers don't.
        sources.push(this.element);
        for (const el of sources) {
            const value = el.getAttribute(attr);
            if (value && value.length > 0) return value;
        }
        return fallback;
    }

    /**
     * One helper for both restore flows, which report the same two facts.
     * Skipped blocks come first: not arriving is worse news than a stray key.
     *
     * @see docs/internals/section-templates.md#skipped-blocks-versus-kept-keys
     */
    _restoreWarning(payload, messages) {
        const skipped = Array.isArray(payload?.skippedBlockTypes) ? payload.skippedBlockTypes : [];
        if (skipped.length > 0) {
            return this._t(...messages.skipped)
                .replace('%count%', String(payload.skippedBlockCount ?? skipped.length))
                .replace('%types%', skipped.join(', '));
        }

        // `unknownFields` for what a template kept, `droppedFields` for what
        // a paste threw away. Same shape, different verb.
        const raw = payload?.[messages.fieldsKey ?? 'unknownFields'];
        const fields = Array.isArray(raw) ? raw : [];
        if (fields.length > 0) {
            const types = [...new Set(fields.map((f) => f.blockType))].join(', ');

            return this._t(...messages.unknown).replace('%types%', types);
        }

        return null;
    }
}
