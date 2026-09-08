import { Controller } from '@hotwired/stimulus';
import { getComponent } from '@symfony/ux-live-component';
import Sortable from 'sortablejs';

/**
 * Drag-and-drop reordering for collection fields, with up/down buttons as the
 * keyboard fallback. SortableJS is a hard dependency, pinned in the importmap.
 *
 * @see docs/internals/forms.md#why-collection-reorder-and-duplicate-flush
 */
export default class extends Controller {
    static values = {
        /** Collection field full_name, e.g. "content_block[tabs]". */
        name: String,
    };

    connect() {
        this._sortable = Sortable.create(this.element, {
            draggable: '.cb-form-collection__item',
            handle: '.cb-form-collection__drag-handle',
            animation: 150,
            onEnd: (event) => {
                const { oldIndex, newIndex } = event;
                if (oldIndex === undefined || newIndex === undefined) return;
                this._move(oldIndex, newIndex);
            },
        });
    }

    disconnect() {
        this._sortable?.destroy();
        this._sortable = null;
    }

    /** Keyboard fallback: move the clicked entry one slot up. */
    moveUp(event) {
        const index = this._indexOf(event.currentTarget);
        if (index > 0) this._move(index, index - 1);
    }

    /** Keyboard fallback: move the clicked entry one slot down. */
    moveDown(event) {
        const index = this._indexOf(event.currentTarget);
        if (index >= 0 && index < this._items().length - 1) this._move(index, index + 1);
    }

    /** Duplicate the clicked entry, inserting the copy right after it. */
    duplicate(event) {
        const index = this._indexOf(event.currentTarget);
        if (index >= 0) this._duplicate(index);
    }

    /** Direct `.cb-form-collection__item` children, in DOM order. */
    _items() {
        return Array.from(this.element.querySelectorAll(':scope > .cb-form-collection__item'));
    }

    /** Position of the item containing `el` among _items(), or -1. */
    _indexOf(el) {
        const item = el.closest('.cb-form-collection__item');
        return item ? this._items().indexOf(item) : -1;
    }

    /**
     * SortableJS already moved the node; the re-render reconciles the
     * positional ids. getComponent() only resolves on the component's *root*.
     */
    _move(from, to) {
        if (from === to || from < 0 || to < 0) return;
        const root = this.element.closest('[data-controller~="live"]');
        if (!root) return;
        getComponent(root)
            .then((component) => component.action('moveCollectionItem', {
                name: this.nameValue,
                from,
                to,
            }))
            .catch(() => {
                /* No Live component in scope — nothing to persist to. */
            });
    }

    /**
     * The component clones the entry's data and inserts it after the original.
     * Same getComponent-on-root caveat as _move().
     */
    _duplicate(index) {
        if (index < 0) return;
        const root = this.element.closest('[data-controller~="live"]');
        if (!root) return;
        getComponent(root)
            .then((component) => component.action('duplicateCollectionItem', {
                name: this.nameValue,
                index,
            }))
            .catch(() => {
                /* No Live component in scope — nothing to persist to. */
            });
    }
}
