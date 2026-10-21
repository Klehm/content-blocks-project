<?php

declare(strict_types=1);

namespace ContentBlocks\Media\Tests\Builder;

use ContentBlocks\Entity\ContentArea;
use ContentBlocks\Media\Builder\MediaActionProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Translation\TranslatableMessage;

final class MediaActionProviderTest extends TestCase
{
    public function testTheActionsMenuOffersTheLibrary(): void
    {
        $actions = iterator_to_array((new MediaActionProvider(['image', 'video']))->getActions(new ContentArea()), false);

        $this->assertCount(1, $actions);
        $this->assertSame(MediaActionProvider::KEY, $actions[0]->key);
        $this->assertInstanceOf(TranslatableMessage::class, $actions[0]->label);
        $this->assertSame('cb_media.action.library', $actions[0]->label->getMessage());
        $this->assertStringContainsString('<svg', (string) $actions[0]->icon);
    }

    // The picker script listens for this exact key.
    public function testTheKeyIsTheOneThePickerListensFor(): void
    {
        $script = (string) file_get_contents(\dirname(__DIR__, 2) . '/assets/picker.js');

        $this->assertStringContainsString("ACTION_KEY = '" . MediaActionProvider::KEY . "'", $script);
    }

    public function testNoKindNoEntry(): void
    {
        $this->assertSame([], iterator_to_array((new MediaActionProvider([]))->getActions(new ContentArea()), false));
    }
}
