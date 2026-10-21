<?php

declare(strict_types=1);

namespace ContentBlocks\Media\Tests\Fixtures;

/** A DBAL exception, whichever of DBAL 3 (a class) or 4 (an interface). */
final class DbalFailure
{
    public static function make(string $message = 'table cb_media does not exist'): \Throwable
    {
        if (interface_exists(\Doctrine\DBAL\Exception::class)) {
            return new class ($message) extends \RuntimeException implements \Doctrine\DBAL\Exception {
            };
        }

        /** @phpstan-ignore new.interface */
        return new \Doctrine\DBAL\Exception($message);
    }
}
