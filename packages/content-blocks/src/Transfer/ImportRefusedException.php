<?php

declare(strict_types=1);

namespace ContentBlocks\Transfer;

/**
 * An import refused for a reason the editor may read. Any other exception
 * during an import answers a generic message: its text is not for the client.
 */
final class ImportRefusedException extends \InvalidArgumentException
{
}
