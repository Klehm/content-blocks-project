import { Controller } from '@hotwired/stimulus';

/**
 * Cross-column block drag & drop controller.
 * Attach to the content area builder root.
 * Blocks can be dragged between any columns across all sections.
 * Drag is initiated only from the .cb-block__handle element.
 */
export default class extends Controller {
    connect() {
        this._onHandleMouseDown = this._onHandleMouseDown.bind(this);
        this._onDragStart = this._onDragStart.bind(this);
        this._onDragOver = this._onDragOver.bind(this);
        this._onDragLeave = this._onDragLeave.bind(this);
        this._onDrop = this._onDrop.bind(this);
        this._onDragEnd = this._onDragEnd.bind(this);

        this.element.addEventListener('mousedown', this._onHandleMouseDown, true);
        this.element.addEventListener('dragstart', this._onDragStart);
        this.element.addEventListener('dragover', this._onDragOver);
        this.element.addEventListener('dragleave', this._onDragLeave);
        this.element.addEventListener('drop', this._onDrop);
        this.element.addEventListener('dragend', this._onDragEnd);
    }

    disconnect() {
        this.element.removeEventListener('mousedown', this._onHandleMouseDown, true);
        this.element.removeEventListener('dragstart', this._onDragStart);
        this.element.removeEventListener('dragover', this._onDragOver);
        this.element.removeEventListener('dragleave', this._onDragLeave);
        this.element.removeEventListener('drop', this._onDrop);
        this.element.removeEventListener('dragend', this._onDragEnd);
    }

    // Enable draggable only when mousedown is on the handle
    _onHandleMouseDown(event) {
        const handle = event.target.closest('.cb-block__handle');
        if (!handle) return;

        const block = handle.closest('.cb-block');
        if (block) {
            block.setAttribute('draggable', 'true');
        }
    }

    _onDragStart(event) {
        const el = event.target instanceof Element ? event.target : event.target.parentElement;
        const block = el?.closest('.cb-block');
        if (!block || block.getAttribute('draggable') !== 'true') return;

        this._draggedBlock = block;
        block.classList.add('cb-dragging');
        event.dataTransfer.effectAllowed = 'move';
        event.dataTransfer.setData('text/plain', block.dataset.blockId);
    }

    _onDragOver(event) {
        if (!this._draggedBlock) return;

        const dropZone = event.target.closest('[data-cb-block-drop-target]');
        if (!dropZone) return;

        event.preventDefault();
        event.dataTransfer.dropEffect = 'move';

        // Find insertion point
        const blocks = [...dropZone.querySelectorAll('.cb-block:not(.cb-dragging)')];
        const afterBlock = this._getInsertionPoint(blocks, event.clientY);

        // Clear all indicators
        this._clearDropIndicators();

        if (afterBlock) {
            afterBlock.classList.add('cb-drop-before');
        } else {
            dropZone.classList.add('cb-drop-inside');
        }
    }

    _onDragLeave(event) {
        const dropZone = event.target.closest('[data-cb-block-drop-target]');
        if (dropZone && !dropZone.contains(event.relatedTarget)) {
            dropZone.classList.remove('cb-drop-inside');
        }
    }

    _onDrop(event) {
        event.preventDefault();
        if (!this._draggedBlock) return;

        const dropZone = event.target.closest('[data-cb-block-drop-target]');
        if (!dropZone) return;

        const blockId = this._draggedBlock.dataset.blockId;
        const targetColumnId = dropZone.dataset.columnId;

        // Find the Live Component wrapper around the dragged block
        const blockWrapper = this._draggedBlock.closest('[data-controller="live"]') || this._draggedBlock;

        // Find insertion point
        const blocks = [...dropZone.querySelectorAll('.cb-block:not(.cb-dragging)')];
        const afterBlock = this._getInsertionPoint(blocks, event.clientY);

        if (afterBlock) {
            const afterWrapper = afterBlock.closest('[data-controller="live"]') || afterBlock;
            dropZone.insertBefore(blockWrapper, afterWrapper);
        } else {
            dropZone.appendChild(blockWrapper);
        }

        // Calculate new position
        const allBlocks = [...dropZone.querySelectorAll('.cb-block')];
        const position = allBlocks.indexOf(this._draggedBlock);

        this._clearDropIndicators();
        this._persist(blockId, targetColumnId, position);
    }

    _onDragEnd() {
        if (this._draggedBlock) {
            this._draggedBlock.classList.remove('cb-dragging');
            this._draggedBlock.setAttribute('draggable', 'false');
            this._draggedBlock = null;
        }
        this._clearDropIndicators();
    }

    _getInsertionPoint(blocks, y) {
        // Find the block that the cursor is above (insert before it)
        for (const block of blocks) {
            const rect = block.getBoundingClientRect();
            if (y < rect.top + rect.height / 2) {
                return block;
            }
        }
        return null; // append at end
    }

    _clearDropIndicators() {
        this.element.querySelectorAll('.cb-drop-before').forEach(el => el.classList.remove('cb-drop-before'));
        this.element.querySelectorAll('.cb-drop-inside').forEach(el => el.classList.remove('cb-drop-inside'));
    }

    _getCsrfToken() {
        return this.element.closest('[data-cb-csrf-token]')?.dataset.cbCsrfToken || '';
    }

    async _persist(blockId, columnId, position) {
        try {
            await fetch(`/_content-blocks/block/${blockId}/move`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-Token': this._getCsrfToken(),
                },
                body: JSON.stringify({ columnId: parseInt(columnId), position }),
            });
        } catch (e) {
            console.error('Failed to persist block move:', e);
        }
    }
}
