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
     * A snapshot may be inserted long after it was saved, so two compatibility
     * checks run against the *current* block-type registry:
     *  - **unknown block type — hard stop.** A block whose type is gone has no
     *    form and no renderer, so the whole insert is refused rather than
     *    dropping content silently.
     *  - **unknown data field — soft warning.** A stored key that nothing in
     *    the block's current shape can hold is reported, never dropped: the
     *    value is kept verbatim and the editor decides.
     *
     * @param array<string, mixed> $payload
     *
     * @throws IncompatibleTemplateException when a referenced block type is not registered
     */
    public function instantiate(array $payload): InstantiationResult;
}
