<?php

declare(strict_types=1);

namespace ContentBlocks\Security;

final class ContentBlocksAccessDeniedException extends \RuntimeException
{
    public function __construct(string $message = 'Access denied to this ContentArea.')
    {
        parent::__construct($message);
    }
}
