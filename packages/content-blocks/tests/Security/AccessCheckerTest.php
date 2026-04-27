<?php

declare(strict_types=1);

namespace ContentBlocks\Tests\Security;

use ContentBlocks\Entity\ContentArea;
use ContentBlocks\Security\AllowAllAccessChecker;
use ContentBlocks\Security\DenyAllAccessChecker;
use PHPUnit\Framework\TestCase;

final class AccessCheckerTest extends TestCase
{
    public function testDenyAllCheckerDeniesEdit(): void
    {
        $checker = new DenyAllAccessChecker();
        $contentArea = new ContentArea();

        $this->assertFalse($checker->canEdit($contentArea));
    }

    public function testDenyAllCheckerDeniesView(): void
    {
        $checker = new DenyAllAccessChecker();
        $contentArea = new ContentArea();

        $this->assertFalse($checker->canView($contentArea));
    }

    public function testAllowAllCheckerAllowsEdit(): void
    {
        $checker = new AllowAllAccessChecker();
        $contentArea = new ContentArea();

        $this->assertTrue($checker->canEdit($contentArea));
    }

    public function testAllowAllCheckerAllowsView(): void
    {
        $checker = new AllowAllAccessChecker();
        $contentArea = new ContentArea();

        $this->assertTrue($checker->canView($contentArea));
    }
}
