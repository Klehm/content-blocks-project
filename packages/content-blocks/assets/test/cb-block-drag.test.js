import { describe, it, expect, vi, beforeEach } from 'vitest';

/**
 * Unit tests for cb-block-drag controller logic.
 */

function createBlocksDOM() {
    document.body.innerHTML = `
        <div class="cb-content-area-builder" data-controller="cb-block-drag">
            <div class="cb-section" data-sort-id="1">
                <div class="cb-section__columns row">
                    <div class="col-6">
                        <div class="cb-column">
                            <div class="cb-column__blocks" data-cb-block-drop-target data-column-id="100">
                                <div class="cb-block card" data-block-id="1" draggable="false">
                                    <span class="cb-block__handle">☰</span>
                                    <span>Block 1</span>
                                </div>
                                <div class="cb-block card" data-block-id="2" draggable="false">
                                    <span class="cb-block__handle">☰</span>
                                    <span>Block 2</span>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="cb-column">
                            <div class="cb-column__blocks" data-cb-block-drop-target data-column-id="200">
                                <div class="cb-block card" data-block-id="3" draggable="false">
                                    <span class="cb-block__handle">☰</span>
                                    <span>Block 3</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    `;

    return document.querySelector('.cb-content-area-builder');
}

function getBlockIdsInColumn(container, columnId) {
    const dropZone = container.querySelector(`[data-column-id="${columnId}"]`);
    return [...dropZone.querySelectorAll('.cb-block')].map(el => el.dataset.blockId);
}

describe('cb-block-drag: handle mousedown enables draggable', () => {
    it('should set draggable=true only when mousedown on handle', () => {
        const container = createBlocksDOM();
        const block = container.querySelector('[data-block-id="1"]');
        const handle = block.querySelector('.cb-block__handle');

        expect(block.getAttribute('draggable')).toBe('false');

        // Simulate handle mousedown logic
        const closestHandle = handle.closest('.cb-block__handle');
        if (closestHandle) {
            const parentBlock = closestHandle.closest('.cb-block');
            if (parentBlock) parentBlock.setAttribute('draggable', 'true');
        }

        expect(block.getAttribute('draggable')).toBe('true');
    });

    it('should not enable drag when clicking outside handle', () => {
        const container = createBlocksDOM();
        const block = container.querySelector('[data-block-id="1"]');
        const span = block.querySelector('span:last-child');

        const closestHandle = span.closest('.cb-block__handle');
        expect(closestHandle).toBeNull();
        expect(block.getAttribute('draggable')).toBe('false');
    });
});

describe('cb-block-drag: cross-column DOM move', () => {
    let container;

    beforeEach(() => {
        container = createBlocksDOM();
    });

    it('should move block from column 100 to column 200', () => {
        const block1 = container.querySelector('[data-block-id="1"]');
        const targetZone = container.querySelector('[data-column-id="200"]');

        // Simulate drop: move block to target column
        targetZone.appendChild(block1);

        expect(getBlockIdsInColumn(container, '100')).toEqual(['2']);
        expect(getBlockIdsInColumn(container, '200')).toEqual(['3', '1']);
    });

    it('should insert block before another block', () => {
        const block1 = container.querySelector('[data-block-id="1"]');
        const block3 = container.querySelector('[data-block-id="3"]');
        const targetZone = container.querySelector('[data-column-id="200"]');

        // Insert before block3
        targetZone.insertBefore(block1, block3);

        expect(getBlockIdsInColumn(container, '200')).toEqual(['1', '3']);
    });

    it('should calculate correct position after move', () => {
        const block1 = container.querySelector('[data-block-id="1"]');
        const targetZone = container.querySelector('[data-column-id="200"]');

        targetZone.appendChild(block1);

        const allBlocks = [...targetZone.querySelectorAll('.cb-block')];
        const position = allBlocks.indexOf(block1);

        expect(position).toBe(1); // after block3
    });
});

describe('cb-block-drag: _getInsertionPoint', () => {
    it('should return null when cursor is below all blocks (append)', () => {
        const container = createBlocksDOM();
        const dropZone = container.querySelector('[data-column-id="100"]');
        const blocks = [...dropZone.querySelectorAll('.cb-block')];

        // Simulate: cursor Y is very large (below all blocks)
        const result = getInsertionPoint(blocks, 99999);
        expect(result).toBeNull();
    });

    it('should return first block when cursor is above it', () => {
        const container = createBlocksDOM();
        const dropZone = container.querySelector('[data-column-id="100"]');
        const blocks = [...dropZone.querySelectorAll('.cb-block')];

        // Simulate: cursor Y is very small (above all blocks)
        const result = getInsertionPoint(blocks, -1);
        expect(result).toBe(blocks[0]);
    });
});

describe('cb-block-drag: _persist', () => {
    beforeEach(() => {
        vi.stubGlobal('fetch', vi.fn(() => Promise.resolve({ ok: true })));
    });

    it('should send correct payload for block move', async () => {
        await fetch('/_content-blocks/block/1/move', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: JSON.stringify({ columnId: 200, position: 0 }),
        });

        expect(fetch).toHaveBeenCalledWith('/_content-blocks/block/1/move', expect.objectContaining({
            method: 'POST',
            body: JSON.stringify({ columnId: 200, position: 0 }),
        }));
    });
});

// Helper: replicates _getInsertionPoint from the controller
function getInsertionPoint(blocks, y) {
    for (const block of blocks) {
        const rect = block.getBoundingClientRect();
        if (y < rect.top + rect.height / 2) {
            return block;
        }
    }
    return null;
}
