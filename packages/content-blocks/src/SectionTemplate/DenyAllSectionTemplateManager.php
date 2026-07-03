<?php

declare(strict_types=1);

namespace ContentBlocks\SectionTemplate;

/**
 * Default implementation: denies management of the section-template library.
 * Forces the host application to opt in by registering its own
 * SectionTemplateManagerInterface implementation.
 */
final class DenyAllSectionTemplateManager implements SectionTemplateManagerInterface
{
    public function canManage(): bool
    {
        return false;
    }
}
