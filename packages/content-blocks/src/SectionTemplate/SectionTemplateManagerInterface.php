<?php

declare(strict_types=1);

namespace ContentBlocks\SectionTemplate;

/**
 * Authorizes management (rename / delete) of the shared, global section-template
 * library.
 *
 * Saving a section into the library and inserting a template into an area are
 * gated by AccessCheckerInterface::canEdit() on the area at hand — you can only
 * capture or drop content where you may already edit. Managing the library
 * itself, however, is a cross-area concern with no ContentArea to key off, so it
 * gets its own capability rather than overloading AccessCheckerInterface.
 *
 * The default DenyAllSectionTemplateManager blocks management (secure by
 * default); dev/sandbox environments alias AllowAllSectionTemplateManager.
 */
interface SectionTemplateManagerInterface
{
    public function canManage(): bool;
}
