<?php

declare(strict_types=1);

namespace ContentBlocks\Tests\Builder;

use ContentBlocks\Builder\BuilderShellFragment;
use PHPUnit\Framework\TestCase;

final class BuilderShellFragmentTest extends TestCase
{
    public function testContextAndPriorityDefaultToEmptyAndZero(): void
    {
        $fragment = new BuilderShellFragment('@Acme/builder/history.html.twig');

        $this->assertSame('@Acme/builder/history.html.twig', $fragment->template);
        $this->assertSame([], $fragment->context);
        $this->assertSame(0, $fragment->priority);
    }

    public function testAnEmptyTemplateNameIsRejectedLoudly(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new BuilderShellFragment('');
    }

    /**
     * The shell merges the edited area on top of the fragment's context. A
     * fragment declaring its own `area` would see it silently replaced, so
     * the value object refuses it at construction instead.
     */
    public function testTheAreaContextVariableIsReserved(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('"area"');

        new BuilderShellFragment('@Acme/x.html.twig', ['area' => 'mine']);
    }

    public function testOtherContextKeysPassThroughUntouched(): void
    {
        $fragment = new BuilderShellFragment('@Acme/x.html.twig', ['revisionCount' => 3, 'assetUrl' => '/x.js']);

        $this->assertSame(['revisionCount' => 3, 'assetUrl' => '/x.js'], $fragment->context);
    }
}
