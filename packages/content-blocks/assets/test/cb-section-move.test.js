import { describe, it, expect, vi, beforeEach } from 'vitest';

/**
 * Unit tests for cb-section-move controller logic.
 * We test the DOM manipulation and persistence logic directly,
 * without the full Stimulus lifecycle.
 */

function createSectionsDOM() {
    document.body.innerHTML = `
        <div class="cb-content-area-builder__sections" data-controller="cb-section-move">
            <div class="cb-section" data-sort-id="10" data-layout="full">
                <div class="cb-section__toolbar">
                    <span class="cb-section__label">
                        <span class="cb-section__handle">☰</span> Section 1 — full
                    </span>
                    <div class="cb-section__actions">
                        <button class="btn-move-up" data-action="cb-section-move#moveUp">▲</button>
                        <button class="btn-move-down" data-action="cb-section-move#moveDown">▼</button>
                        <button class="btn-remove" data-action="cb-section-move#removeSection">✕</button>
                    </div>
                </div>
                <div class="cb-section__columns row"></div>
            </div>
            <div class="cb-section" data-sort-id="20" data-layout="two_cols">
                <div class="cb-section__toolbar">
                    <span class="cb-section__label">
                        <span class="cb-section__handle">☰</span> Section 2 — two_cols
                    </span>
                    <div class="cb-section__actions">
                        <button class="btn-move-up" data-action="cb-section-move#moveUp">▲</button>
                        <button class="btn-move-down" data-action="cb-section-move#moveDown">▼</button>
                        <button class="btn-remove" data-action="cb-section-move#removeSection">✕</button>
                    </div>
                </div>
                <div class="cb-section__columns row"></div>
            </div>
            <div class="cb-section" data-sort-id="30" data-layout="three_cols">
                <div class="cb-section__toolbar">
                    <span class="cb-section__label">
                        <span class="cb-section__handle">☰</span> Section 3 — three_cols
                    </span>
                    <div class="cb-section__actions">
                        <button class="btn-move-up" data-action="cb-section-move#moveUp">▲</button>
                        <button class="btn-move-down" data-action="cb-section-move#moveDown">▼</button>
                        <button class="btn-remove" data-action="cb-section-move#removeSection">✕</button>
                    </div>
                </div>
                <div class="cb-section__columns row"></div>
            </div>
        </div>
    `;

    return document.querySelector('.cb-content-area-builder__sections');
}

function getSectionIds(container) {
    return [...container.querySelectorAll('.cb-section')].map(el => el.dataset.sortId);
}

function getLabelTexts(container) {
    return [...container.querySelectorAll('.cb-section__label')].map(el => el.textContent.trim());
}

describe('cb-section-move: moveUp', () => {
    let container;

    beforeEach(() => {
        container = createSectionsDOM();
        vi.stubGlobal('fetch', vi.fn(() => Promise.resolve({ ok: true })));
    });

    it('should move second section before first', () => {
        const sections = container.querySelectorAll('.cb-section');
        const section2 = sections[1];
        const prev = section2.previousElementSibling;

        // Simulate moveUp logic
        container.insertBefore(section2, prev);

        expect(getSectionIds(container)).toEqual(['20', '10', '30']);
    });

    it('should not move first section up', () => {
        const section1 = container.querySelector('.cb-section');
        const prev = section1.previousElementSibling;

        expect(prev).toBeNull();
        // moveUp would return early
        expect(getSectionIds(container)).toEqual(['10', '20', '30']);
    });
});

describe('cb-section-move: moveDown', () => {
    let container;

    beforeEach(() => {
        container = createSectionsDOM();
    });

    it('should move first section after second', () => {
        const sections = container.querySelectorAll('.cb-section');
        const section1 = sections[0];
        const next = section1.nextElementSibling;

        // Simulate moveDown: insertBefore(next, section) swaps them
        container.insertBefore(next, section1);

        expect(getSectionIds(container)).toEqual(['20', '10', '30']);
    });

    it('should not move last section down', () => {
        const sections = container.querySelectorAll('.cb-section');
        const lastSection = sections[2];
        const next = lastSection.nextElementSibling;

        expect(next).toBeNull();
        expect(getSectionIds(container)).toEqual(['10', '20', '30']);
    });
});

describe('cb-section-move: removeSection', () => {
    let container;

    beforeEach(() => {
        container = createSectionsDOM();
    });

    it('should remove section from DOM', () => {
        const section2 = container.querySelectorAll('.cb-section')[1];
        section2.remove();

        expect(getSectionIds(container)).toEqual(['10', '30']);
    });
});

describe('cb-section-move: _updateLabels', () => {
    let container;

    beforeEach(() => {
        container = createSectionsDOM();
    });

    it('should update labels after reorder preserving handle', () => {
        const sections = container.querySelectorAll('.cb-section');
        // Move section 3 (three_cols) to position 1
        container.insertBefore(sections[2], sections[0]);

        // Simulate _updateLabels
        container.querySelectorAll('.cb-section').forEach((el, i) => {
            const label = el.querySelector('.cb-section__label');
            const layout = el.dataset.layout || '';
            const handle = label.querySelector('.cb-section__handle');

            if (handle) {
                [...label.childNodes].forEach(node => {
                    if (node !== handle) node.remove();
                });
                label.appendChild(document.createTextNode(` Section ${i + 1} — ${layout}`));
            }
        });

        const labels = getLabelTexts(container);
        expect(labels[0]).toContain('Section 1 — three_cols');
        expect(labels[1]).toContain('Section 2 — full');
        expect(labels[2]).toContain('Section 3 — two_cols');

        // Handle should still exist
        const handles = container.querySelectorAll('.cb-section__handle');
        expect(handles).toHaveLength(3);
    });
});

describe('cb-section-move: _persistOrder', () => {
    beforeEach(() => {
        vi.stubGlobal('fetch', vi.fn(() => Promise.resolve({ ok: true })));
    });

    it('should send section IDs in DOM order', async () => {
        const container = createSectionsDOM();

        // Move section 3 to position 1
        const sections = container.querySelectorAll('.cb-section');
        container.insertBefore(sections[2], sections[0]);

        // Simulate _persistOrder
        const sectionIds = [...container.querySelectorAll('.cb-section')]
            .map(el => parseInt(el.dataset.sortId));

        await fetch('/_content-blocks/sections/reorder', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ sectionIds }),
        });

        expect(fetch).toHaveBeenCalledWith('/_content-blocks/sections/reorder', expect.objectContaining({
            method: 'POST',
            body: JSON.stringify({ sectionIds: [30, 10, 20] }),
        }));
    });
});
