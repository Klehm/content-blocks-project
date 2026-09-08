<?php

declare(strict_types=1);

namespace ContentBlocks\SectionTemplate;

/**
 * Default: denies library management, so a host has to opt in by registering
 * its own {@see SectionTemplateManagerInterface}.
 */
final class DenyAllSectionTemplateManager implements SectionTemplateManagerInterface
{
    public function canManage(): bool
    {
        return false;
    }
}
