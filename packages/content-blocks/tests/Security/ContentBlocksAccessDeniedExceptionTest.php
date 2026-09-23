<?php

declare(strict_types=1);

namespace ContentBlocks\Tests\Security;

use ContentBlocks\Security\ContentBlocksAccessDeniedException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

final class ContentBlocksAccessDeniedExceptionTest extends TestCase
{
    // A denial answered 500 was logged as a crash and hid the real status.
    public function testADenialIsAnHttp403(): void
    {
        $exception = new ContentBlocksAccessDeniedException();

        $this->assertInstanceOf(HttpExceptionInterface::class, $exception);
        $this->assertSame(403, $exception->getStatusCode());
    }

    public function testItIsStillARuntimeException(): void
    {
        $this->assertInstanceOf(\RuntimeException::class, new ContentBlocksAccessDeniedException());
    }
}
