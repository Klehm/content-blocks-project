import { Controller } from '@hotwired/stimulus';
import { getComponent } from '@symfony/ux-live-component';
import Sortable from 'sortablejs';

/**
 * Drag-and-drop reordering for LiveCollectionType fields rendered in the
 * builder sidebar (cards, FAQ entries, tabs…).
 *
 * Attached by the cb_form_theme `live_collection_widget` block to the
 * collection's widget container. The direct `.cb-form-collection__item`
 * children are the sortable entries; each carries a drag handle plus
 * up/down buttons (keyboard-accessible fallback).
 *
 * On reorder we call the Block live action `moveCollectionItem`, passing
 * the collection field's full name and the 0-based source/target DOM
 * positions. The component reorders its form data positionally and
 * re-renders; cb-autosave then persists the new order to the draft and
 * reloads the preview — the exact same structural-edit path that
 * add/delete already use, so a reorder is a single, deduped save.
 *
 * SortableJS is a hard dependency, pinned in the host importmap (run
 * `php bin/console importmap:require sortablejs`). The up/down buttons
 * provide a keyboard-accessible alternative to dragging.
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
     * Persist a reorder via the Block live action. SortableJS has already
     * moved the DOM node; the live re-render reconciles the (positional)
     * widget ids back to the new order, so the visible order stays put.
     *
     * getComponent() resolves only when handed the component's *root*
     * element, so walk up to the nearest Live controller (our element is
     * the collection widget container, nested inside it).
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
     * Persist a duplicate via the Block live action. The component clones the
     * entry's form data, inserts the copy after the original and re-renders;
     * cb-autosave then reloads the preview — same structural-edit path as
     * add/delete/reorder. Same getComponent-on-root caveat as _move().
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
