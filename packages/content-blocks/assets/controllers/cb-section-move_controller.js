import { Controller } from '@hotwired/stimulus';

/**
 * Handles section reordering via:
 * - Buttons: moveUp / moveDown
 * - Drag & drop: grab handle ☰
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

    // --- Button actions ---

    _getCsrfToken() {
        return this.element.closest('[data-cb-csrf-token]')?.dataset.cbCsrfToken || '';
    }

    async removeSection(event) {
        const section = event.currentTarget.closest('.cb-section');
        if (!section) return;

        const sectionId = section.dataset.sortId;
        section.remove();
        this._updateLabels();

        try {
            await fetch(`/_content-blocks/section/${sectionId}/delete`, {
                method: 'POST',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-Token': this._getCsrfToken(),
                },
            });
        } catch (e) {
            console.error('Failed to delete section:', e);
        }
    }

    async moveUp(event) {
        const section = event.currentTarget.closest('.cb-section');
        const prev = section.previousElementSibling;

        if (!prev || !prev.classList.contains('cb-section')) return;

        section.parentElement.insertBefore(section, prev);
        this._updateLabels();
        await this._persistOrder();
    }

    async moveDown(event) {
        const section = event.currentTarget.closest('.cb-section');
        const next = section.nextElementSibling;

        if (!next || !next.classList.contains('cb-section')) return;

        section.parentElement.insertBefore(next, section);
        this._updateLabels();
        await this._persistOrder();
    }

    // --- Drag & drop ---

    _onHandleMouseDown(event) {
        const handle = event.target.closest('.cb-section__handle');
        if (!handle) return;

        const section = handle.closest('.cb-section');
        if (section) {
            section.setAttribute('draggable', 'true');
        }
    }

    _onDragStart(event) {
        const el = event.target instanceof Element ? event.target : event.target.parentElement;
        const section = el?.closest('.cb-section');
        if (!section || section.getAttribute('draggable') !== 'true') return;

        // Ignore if a block inside the section is being dragged
        const block = el?.closest('.cb-block');
        if (block) return;

        this._draggedSection = section;
        section.classList.add('cb-dragging');
        event.dataTransfer.effectAllowed = 'move';
        event.dataTransfer.setData('text/plain', section.dataset.sortId);
    }

    _onDragOver(event) {
        if (!this._draggedSection) return;

        const section = this._getSectionFromEvent(event);
        if (!section || section === this._draggedSection) return;

        event.preventDefault();
        event.dataTransfer.dropEffect = 'move';

        this._clearDropIndicators();

        const rect = section.getBoundingClientRect();
        const midY = rect.top + rect.height / 2;

        if (event.clientY < midY) {
            section.classList.add('cb-drop-before');
        } else {
            section.classList.add('cb-drop-after');
        }
    }

    _onDragLeave(event) {
        const section = this._getSectionFromEvent(event);
        if (section) {
            section.classList.remove('cb-drop-before', 'cb-drop-after');
        }
    }

    _onDrop(event) {
        event.preventDefault();
        if (!this._draggedSection) return;

        const targetSection = this._getSectionFromEvent(event);
        if (!targetSection || targetSection === this._draggedSection) return;

        const rect = targetSection.getBoundingClientRect();
        const midY = rect.top + rect.height / 2;

        if (event.clientY < midY) {
            targetSection.parentElement.insertBefore(this._draggedSection, targetSection);
        } else {
            targetSection.after(this._draggedSection);
        }

        this._clearDropIndicators();
        this._updateLabels();
        this._persistOrder();
    }

    _onDragEnd() {
        if (this._draggedSection) {
            this._draggedSection.classList.remove('cb-dragging');
            this._draggedSection.setAttribute('draggable', 'false');
            this._draggedSection = null;
        }
        this._clearDropIndicators();
    }

    _getSectionFromEvent(event) {
        const el = event.target instanceof Element ? event.target : event.target.parentElement;
        return el?.closest('.cb-section');
    }

    _clearDropIndicators() {
        this.element.querySelectorAll('.cb-drop-before, .cb-drop-after').forEach(el => {
            el.classList.remove('cb-drop-before', 'cb-drop-after');
        });
    }

    // --- Labels ---

    _updateLabels() {
        this.element.querySelectorAll('.cb-section').forEach((el, i) => {
            const label = el.querySelector('.cb-section__label');
            if (!label) return;

            const layout = el.dataset.layout || '';
            const handle = label.querySelector('.cb-section__handle');

            if (handle) {
                [...label.childNodes].forEach(node => {
                    if (node !== handle) node.remove();
                });
                label.appendChild(document.createTextNode(` Section ${i + 1} — ${layout}`));
            }
        });
    }

    // --- Persistence ---

    async _persistOrder() {
        const sectionIds = [...this.element.querySelectorAll('.cb-section')]
            .map(el => parseInt(el.dataset.sortId));

        try {
            await fetch('/_content-blocks/sections/reorder', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-Token': this._getCsrfToken(),
                },
                body: JSON.stringify({ sectionIds }),
            });
        } catch (e) {
            console.error('Failed to persist section order:', e);
        }
    }
}
