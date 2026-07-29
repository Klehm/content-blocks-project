<?php

declare(strict_types=1);

namespace App\ContentBlocks\Block;

use App\ContentBlocks\Form\AnchorIdExtension;
use ContentBlocks\Block\BlockDecoration;
use ContentBlocks\Block\BlockDecoratorInterface;
use ContentBlocks\Entity\Block;

/**
 * Render half of the global anchor-id example: turns the `anchorId` key added
 * by {@see AnchorIdExtension} into an `id` attribute on the block wrapper.
 *
 * A form extension only adds a *field*; something has to render it. For a
 * cross-cutting field a decorator is the natural counterpart — no template
 * override needed, it applies to every block at once.
 */
final class AnchorIdBlockDecorator implements BlockDecoratorInterface
{
    public function decorate(array $data, Block $block): BlockDecoration
    {
        $anchor = trim((string) ($data['anchorId'] ?? ''));

        // Re-validate at render time: stored data may predate the constraint
        // (import, fixture, older draft), and it lands in an HTML attribute.
        if ('' === $anchor || 1 !== preg_match(AnchorIdExtension::PATTERN, $anchor)) {
            return new BlockDecoration();
        }

        return new BlockDecoration(attributes: ['id' => $anchor]);
    }
}
