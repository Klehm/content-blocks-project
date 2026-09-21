import { Controller } from '@hotwired/stimulus';
import { getComponent } from '@symfony/ux-live-component';
import Sortable from 'sortablejs';

const COLLAPSED = 'cb-form-collection__item--collapsed';

/**
 * Drag-and-drop reordering for collection fields, with up/down buttons as the
 * keyboard fallback, and entries that fold down to a one-line header.
 *
 * @see docs/internals/forms.md#why-collection-reorder-and-duplicate-flush
 */
export default class extends Controller {
    static targets = ['list'];

    static values = {
        /** Collection field full_name, e.g. "content_block[tabs]". */
        name: String,
    };

    connect() {
        // Folded state by position: Live re-renders by position too, so it
        // is re-applied after each render and follows moves made here.
        this._connected = true;
        this._onRender = () => this._applyCollapsed();
        this._onClick = (event) => this._forgetDeleted(event);
        this._list().addEventListener('click', this._onClick);
        this._component()?.then((component) => {
            if (!this._connected || typeof component?.on !== 'function') return;
            this._live = component;
            component.on('render:finished', this._onRender);
        }).catch(() => {});

        this._sortable = Sortable.create(this._list(), {
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
        this._list().removeEventListener('click', this._onClick);
        this._live?.off?.('render:finished', this._onRender);
        this._live = null;
        this._connected = false;
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

    /** Fold or unfold the clicked entry. */
    toggle(event) {
        const index = this._indexOf(event.currentTarget);
        if (index < 0) return;
        const state = this._state();
        state[index] = !state[index];
        this._applyCollapsed();
    }

    collapseAll() {
        this._collapsed = this._items().map(() => true);
        this._applyCollapsed();
    }

    expandAll() {
        this._collapsed = [];
        this._applyCollapsed();
    }

    /** The SortableJS list: a target inside the controller, or the element. */
    _list() {
        return this.hasListTarget ? this.listTarget : this.element;
    }

    /** Direct `.cb-form-collection__item` children, in DOM order. */
    _items() {
        return Array.from(this._list().querySelectorAll(':scope > .cb-form-collection__item'));
    }

    /** Position of the item containing `el` among _items(), or -1. */
    _indexOf(el) {
        const item = el.closest('.cb-form-collection__item');
        return item ? this._items().indexOf(item) : -1;
    }

    /** One boolean per entry, padded to the current count. */
    _state() {
        this._collapsed ??= [];
        while (this._collapsed.length < this._items().length) this._collapsed.push(false);
        return this._collapsed;
    }

    _applyCollapsed() {
        const state = this._state();
        this._items().forEach((item, index) => {
            const collapsed = state[index] === true;
            item.classList.toggle(COLLAPSED, collapsed);
            item.querySelector(':scope > .cb-form-collection__controls > .cb-form-collection__toggle')
                ?.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
        });
    }

    /** Live's own delete button removes an entry: drop its folded state. */
    _forgetDeleted(event) {
        const button = event.target.closest?.('.cb-form-collection__delete');
        if (!button) return;
        const index = this._indexOf(button);
        if (index >= 0) this._state().splice(index, 1);
    }

    /** The Live component owning this field, resolved on its *root*. */
    _component() {
        const root = this.element.closest('[data-controller~="live"]');
        return root ? getComponent(root) : null;
    }

    /**
     * SortableJS already moved the node; the re-render reconciles the
     * positional ids. getComponent() only resolves on the component's *root*.
     */
    _move(from, to) {
        if (from === to || from < 0 || to < 0) return;
        const state = this._state();
        const [folded] = state.splice(from, 1);
        state.splice(to, 0, folded === true);
        this._component()
            ?.then((component) => component.action('moveCollectionItem', {
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
     * Same getComponent-on-root caveat as _move(). The copy opens unfolded.
     */
    _duplicate(index) {
        if (index < 0) return;
        this._state().splice(index + 1, 0, false);
        this._component()
            ?.then((component) => component.action('duplicateCollectionItem', {
                name: this.nameValue,
                index,
            }))
            .catch(() => {
                /* No Live component in scope — nothing to persist to. */
            });
    }
}
