<?php

declare(strict_types=1);

namespace ContentBlocks\SectionTemplate;

/**
 * Authorizes rename and delete on the shared section-template library — a
 * cross-area concern with no ContentArea to key off. Denied by default.
 *
 * @see docs/internals/section-templates.md#managing-the-library
 */
interface SectionTemplateManagerInterface
{
    public function canManage(): bool;
}
