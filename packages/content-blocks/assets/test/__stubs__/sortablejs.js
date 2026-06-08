/**
 * Vitest stub for `sortablejs`.
 *
 * The real library is supplied at runtime by the host's AssetMapper importmap
 * (`importmap:require sortablejs`), not by npm, so vitest can't resolve the
 * bare specifier. The unit tests drive the controller's reorder logic
 * directly and never call connect(), so SortableJS is never actually
 * instantiated — this stub only needs to satisfy the static import.
 */
export default {
    create() {
        return { destroy() {} };
    },
};
