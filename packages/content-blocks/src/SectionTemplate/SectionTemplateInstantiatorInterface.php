<?php

declare(strict_types=1);

namespace ContentBlocks\SectionTemplate;

/**
 * Rebuilds a detached draft Section from a payload produced by
 * {@see SectionTemplateSerializerInterface}.
 *
 * Override seam: the bundle aliases this to the shipped {@see SectionTemplateInstantiator}.
 */
interface SectionTemplateInstantiatorInterface
{
    /**
     * The output mirrors {@see SectionClonerInterface::cloneSection()}: a
     * detached draft Section for the caller to attach, position and flush.
     *
     * A snapshot may be inserted long after it was saved, so the payload is
     * replayed optimistically against the *current* block-type registry:
     *  - **unknown block type — skipped.** A block whose type is gone has no
     *    form and no renderer; inserting it would hand the editor an inert
     *    placeholder. The stored payload remains the archive, so restoring the
     *    block type and re-inserting brings it back.
     *  - **unknown data field — kept.** A stored key that nothing in the
     *    block's current shape can hold is reported, never dropped: the block
     *    itself is usable, and the key may be a field about to be added.
     *
     * The one hard stop left is a template that had blocks and kept none —
     * there is nothing to insert.
     *
     * @param array<string, mixed> $payload
     *
     * @throws UnsupportedTemplateFormatException when the payload envelope is not readable
     * @throws IncompatibleTemplateException      when the template had blocks and none survived
     */
    public function instantiate(array $payload): InstantiationResult;
}
