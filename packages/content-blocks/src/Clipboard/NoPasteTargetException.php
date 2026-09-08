<?php

declare(strict_types=1);

namespace ContentBlocks\Clipboard;

/**
 * A block paste with nothing selected. The builder refuses to guess a column
 * rather than drop content somewhere the editor never looked.
 */
final class NoPasteTargetException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('Pasting a block requires a selected section or block.');
    }
}
