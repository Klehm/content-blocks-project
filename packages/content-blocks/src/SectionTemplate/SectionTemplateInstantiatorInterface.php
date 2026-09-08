<?php

declare(strict_types=1);

namespace ContentBlocks\SectionTemplate;

/**
 * Rebuilds a detached draft Section from a payload produced by
 * {@see SectionTemplateSerializerInterface}. Override seam.
 */
interface SectionTemplateInstantiatorInterface
{
    /**
     * A detached draft Section for the caller to attach, position and flush,
     * replayed optimistically against the *current* block-type registry.
     *
     * @see docs/internals/section-templates.md#skipped-blocks-versus-kept-keys
     *
     * @param array<string, mixed> $payload
     *
     * @throws UnsupportedTemplateFormatException on an unreadable envelope
     * @throws IncompatibleTemplateException      when no block survives
     */
    public function instantiate(array $payload): InstantiationResult;
}
