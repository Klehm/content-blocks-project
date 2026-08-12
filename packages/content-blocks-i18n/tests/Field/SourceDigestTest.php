<?php

declare(strict_types=1);

namespace ContentBlocks\I18n\Tests\Field;

use ContentBlocks\I18n\Field\SourceDigest;
use PHPUnit\Framework\TestCase;

final class SourceDigestTest extends TestCase
{
    public function testTheSameTextAlwaysHashesTheSame(): void
    {
        $this->assertSame(SourceDigest::of('Hello'), SourceDigest::of('Hello'));
    }

    public function testDifferentTextHashesDifferently(): void
    {
        $this->assertNotSame(SourceDigest::of('Hello'), SourceDigest::of('Hello!'));
    }

    public function testWhitespaceIsASignificantChange(): void
    {
        // Deliberately byte-exact: the editor decides whether a whitespace edit
        // matters, and dismissing the flag costs one click — whereas
        // normalizing away a real edit costs a wrong page.
        $this->assertNotSame(SourceDigest::of('Hello'), SourceDigest::of('Hello '));
    }

    public function testNonStringValuesHashWithoutThrowing(): void
    {
        // A host is free to tag something structural; json_encode gives arrays
        // and numbers a stable representation instead of "Array".
        $this->assertSame(SourceDigest::of(['a' => 1]), SourceDigest::of(['a' => 1]));
        $this->assertNotSame(SourceDigest::of(['a' => 1]), SourceDigest::of(['a' => 2]));
        $this->assertNotSame('', SourceDigest::of(null));
    }

    public function testAnAbsentDigestReadsAsUpToDate(): void
    {
        // Rows written before digests existed, or by an import. The pessimistic
        // reading has to be earned by an actual mismatch, or day one of an
        // upgrade floods the workbench with false alarms.
        $this->assertTrue(SourceDigest::matches('anything', null));
        $this->assertTrue(SourceDigest::matches('anything', ''));
    }

    public function testAMismatchIsDetected(): void
    {
        $this->assertTrue(SourceDigest::matches('Hello', SourceDigest::of('Hello')));
        $this->assertFalse(SourceDigest::matches('Hello!', SourceDigest::of('Hello')));
    }
}
