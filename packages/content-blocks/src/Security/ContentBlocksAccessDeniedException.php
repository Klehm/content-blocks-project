<?php

declare(strict_types=1);

namespace ContentBlocks\Security;

use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * A refused access check: answered 403 by the kernel, like any HTTP denial.
 * Still a \RuntimeException, so a catch written for the old parent holds.
 */
final class ContentBlocksAccessDeniedException extends AccessDeniedHttpException
{
    public function __construct(string $message = 'Access denied to this ContentArea.')
    {
        parent::__construct($message);
    }
}
