<?php

declare(strict_types=1);

namespace ContentBlocks\Event;

/**
 * Where inserted content came from. Strings rather than an enum, so a later
 * version can add an origin without breaking a `match` in host code.
 *
 * @see docs/guide/events.md#content-inserted-from-a-payload
 */
final class ContentInsertOrigin
{
    /** Ctrl/Cmd-V: a section or a block from the clipboard. */
    public const PASTE = 'paste';
    /** A section from the section library. */
    public const SECTION_TEMPLATE = 'section_template';
    /** An export file. Replaces the area's sections. */
    public const IMPORT = 'import';
    /** *Insert content*: another area's sections. Replaces the area's. */
    public const REPLACE = 'replace';

    private function __construct()
    {
    }
}
