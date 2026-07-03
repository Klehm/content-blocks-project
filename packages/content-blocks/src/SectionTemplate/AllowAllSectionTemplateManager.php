<?php

declare(strict_types=1);

namespace ContentBlocks\SectionTemplate;

/**
 * Allows management of the section-template library. Use ONLY for
 * development/sandbox environments.
 */
final class AllowAllSectionTemplateManager implements SectionTemplateManagerInterface
{
    public function canManage(): bool
    {
        return true;
    }
}
