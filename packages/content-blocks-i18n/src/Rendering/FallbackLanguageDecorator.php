<?php

declare(strict_types=1);

namespace ContentBlocks\I18n\Rendering;

use ContentBlocks\Block\BlockDecoration;
use ContentBlocks\Block\BlockDecoratorInterface;
use ContentBlocks\Entity\Block;

/**
 * `lang` on the wrapper of a block shown entirely in another language than
 * the page's, so any view is covered without a template change.
 *
 * @see docs/internals/i18n.md#the-language-of-a-fallback
 */
final class FallbackLanguageDecorator implements BlockDecoratorInterface
{
    public function decorate(array $data, Block $block): BlockDecoration
    {
        $tag = $data[FallbackLanguageMarker::BLOCK_KEY] ?? null;

        if (!\is_string($tag) || !FallbackLanguageMarker::isValidTag($tag)) {
            return new BlockDecoration();
        }

        return new BlockDecoration(attributes: ['lang' => $tag]);
    }
}
