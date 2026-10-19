<?php

declare(strict_types=1);

namespace ContentBlocks\Tests\Publishing;

use ContentBlocks\Entity\ContentArea;
use ContentBlocks\Publishing\LocaleDraft;
use ContentBlocks\Publishing\LocaleDrafts;
use ContentBlocks\Publishing\LocaleDraftsProviderInterface;
use PHPUnit\Framework\TestCase;

final class LocaleDraftsTest extends TestCase
{
    // No provider: Publish asks nothing, as before the seam.
    public function testWithNoProviderItIsDisabledAndEmpty(): void
    {
        $drafts = new LocaleDrafts();

        $this->assertFalse($drafts->isEnabled());
        $this->assertSame([], $drafts->of(new ContentArea()));
    }

    // Enabled by being registered, even with nothing pending right now.
    public function testAProviderEnablesItWhateverItAnswers(): void
    {
        $this->assertTrue((new LocaleDrafts([$this->provider()]))->isEnabled());
    }

    // The first provider to name a code keeps its label.
    public function testLocalesAreMergedByCodeInProviderOrder(): void
    {
        $drafts = new LocaleDrafts([
            $this->provider(new LocaleDraft('fr', 'Français')),
            $this->provider(new LocaleDraft('fr', 'French'), new LocaleDraft('de', 'Deutsch')),
        ]);

        $this->assertEquals(
            [new LocaleDraft('fr', 'Français'), new LocaleDraft('de', 'Deutsch')],
            $drafts->of(new ContentArea()),
        );
    }

    private function provider(LocaleDraft ...$drafts): LocaleDraftsProviderInterface
    {
        $provider = $this->createStub(LocaleDraftsProviderInterface::class);
        $provider->method('pendingLocales')->willReturn(array_values($drafts));

        return $provider;
    }
}
