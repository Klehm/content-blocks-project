<?php

declare(strict_types=1);

namespace ContentBlocks\Seo\Builder;

use ContentBlocks\Builder\BuilderAction;
use ContentBlocks\Builder\BuilderActionProviderInterface;
use ContentBlocks\Entity\ContentArea;
use Symfony\Component\Translation\TranslatableMessage;

/**
 * *SEO audit* in the builder's Actions menu; the panel answers the click.
 */
final class SeoActionProvider implements BuilderActionProviderInterface
{
    /** Also in `assets/panel.js`, which switches on it. */
    public const KEY = 'content-blocks-seo.audit';

    private const ICON = '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" '
        . 'stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
        . '<circle cx="11" cy="11" r="6.5"/><path d="m20 20-4.4-4.4"/><path d="m8.5 11 1.8 1.8L13.8 9"/></svg>';

    public function getActions(ContentArea $area): iterable
    {
        yield new BuilderAction(
            self::KEY,
            new TranslatableMessage('cb_seo.action', [], 'content_blocks_seo'),
            self::ICON,
        );
    }
}
