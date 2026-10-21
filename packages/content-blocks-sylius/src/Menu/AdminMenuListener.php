<?php

declare(strict_types=1);

namespace ContentBlocks\Sylius\Menu;

use ContentBlocks\Sylius\Slot\SlotRenderer;
use ContentBlocks\Sylius\Slot\SlotType;
use Sylius\Bundle\UiBundle\Menu\Event\MenuBuilderEvent;

/** A "Content" group in the admin menu: the pages and the homepages. */
final class AdminMenuListener
{
    public function __construct(private readonly SlotRenderer $slots)
    {
    }

    public function addContentMenu(MenuBuilderEvent $event): void
    {
        $content = $event->getMenu()
            ->addChild('content_blocks')
            ->setLabel('content_blocks_sylius.menu.content')
            ->setLabelAttribute('icon', 'tabler:layout-board');

        $content
            ->addChild('content_blocks_pages', ['route' => 'content_blocks_sylius_admin_content_page_index'])
            ->setLabel('content_blocks_sylius.menu.pages')
            ->setLabelAttribute('icon', 'tabler:file-text');

        if ($this->slots->isEnabled(SlotType::HOMEPAGE)) {
            $content
                ->addChild('content_blocks_homepage', ['route' => 'content_blocks_sylius_admin_homepage'])
                ->setLabel('content_blocks_sylius.menu.homepage')
                ->setLabelAttribute('icon', 'tabler:home');
        }
    }
}
