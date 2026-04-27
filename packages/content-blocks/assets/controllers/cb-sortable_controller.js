import { Controller } from '@hotwired/stimulus';

/**
 * Simple drag & drop controller for sections and blocks.
 * Uses native HTML5 Drag and Drop API.
 */
export default class extends Controller {
    static values = {
        type: String, // "section" or "block"
    };

    connect() {
        this.element.querySelectorAll('[data-sort-id]').forEach((el) => {
            el.setAttribute('draggable', 'true');
            el.addEventListener('dragstart', this._onDragStart.bind(this));
            el.addEventListener('dragover', this._onDragOver.bind(this));
            el.addEventListener('drop', this._onDrop.bind(this));
            el.addEventListener('dragend', this._onDragEnd.bind(this));
        });
    }

    _onDragStart(event) {
        event.dataTransfer.effectAllowed = 'move';
        event.dataTransfer.setData('text/plain', event.currentTarget.dataset.sortId);
        event.currentTarget.classList.add('cb-dragging');
    }

    _onDragOver(event) {
        event.preventDefault();
        event.dataTransfer.dropEffect = 'move';
        event.currentTarget.classList.add('cb-drag-over');
    }

    _onDrop(event) {
        event.preventDefault();
        const fromId = event.dataTransfer.getData('text/plain');
        const toId = event.currentTarget.dataset.sortId;

        event.currentTarget.classList.remove('cb-drag-over');

        if (fromId === toId) return;

        // Swap DOM elements visually
        const container = this.element;
        const fromEl = container.querySelector(`[data-sort-id="${fromId}"]`);
        const toEl = container.querySelector(`[data-sort-id="${toId}"]`);

        if (fromEl && toEl) {
            const fromRect = fromEl.getBoundingClientRect();
            const toRect = toEl.getBoundingClientRect();

            if (fromRect.top < toRect.top) {
                toEl.after(fromEl);
            } else {
                toEl.before(fromEl);
            }
        }
    }

    _onDragEnd(event) {
        event.currentTarget.classList.remove('cb-dragging');
        this.element.querySelectorAll('.cb-drag-over').forEach((el) => {
            el.classList.remove('cb-drag-over');
        });
    }
}
