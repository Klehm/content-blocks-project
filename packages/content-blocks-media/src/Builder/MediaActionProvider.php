<?php

declare(strict_types=1);

namespace ContentBlocks\Media\Builder;

use ContentBlocks\Builder\BuilderAction;
use ContentBlocks\Builder\BuilderActionProviderInterface;
use ContentBlocks\Entity\ContentArea;
use Symfony\Component\Translation\TranslatableMessage;

/**
 * *Media library* in the builder's Actions menu: the picker opened for no
 * field, to sort, rename and upload ahead of time.
 */
final class MediaActionProvider implements BuilderActionProviderInterface
{
    public const KEY = 'content-blocks-media.library';

    private const ICON = '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" '
        . 'stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
        . '<rect x="3" y="5" width="18" height="14" rx="2"/><circle cx="9" cy="10" r="1.6"/>'
        . '<path d="m21 16-5-5-8 8"/></svg>';

    /** @param list<string> $kinds */
    public function __construct(private readonly array $kinds)
    {
    }

    public function getActions(ContentArea $area): iterable
    {
        if ($this->kinds === []) {
            return;
        }

        yield new BuilderAction(
            self::KEY,
            new TranslatableMessage('cb_media.action.library', [], 'content_blocks_media'),
            self::ICON,
        );
    }
}
