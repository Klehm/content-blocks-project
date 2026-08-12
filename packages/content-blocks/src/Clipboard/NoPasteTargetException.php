<?php

declare(strict_types=1);

namespace ContentBlocks\Clipboard;

/**
 * A block paste with nothing selected: there is no column to put it in, and the
 * builder is not going to pick one for the editor. The UI says so instead —
 * "select a section or a block first" — rather than dropping content somewhere
 * the editor did not look at.
 */
final class NoPasteTargetException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('Pasting a block requires a selected section or block.');
    }
}
