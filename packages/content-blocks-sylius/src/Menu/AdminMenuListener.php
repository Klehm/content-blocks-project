<?php

declare(strict_types=1);

namespace ContentBlocks\Sylius\Menu;

use ContentBlocks\Sylius\Slot\SlotRenderer;
use ContentBlocks\Sylius\Slot\SlotType;
use Knp\Menu\ItemInterface;
use Sylius\Bundle\UiBundle\Menu\Event\MenuBuilderEvent;

/** A "Content" group in the admin menu: the pages and the homepages. */
final class AdminMenuListener
{
    public function __construct(private readonly SlotRenderer $slots)
    {
    }

    public function addContentMenu(MenuBuilderEvent $event): void
    {
        $menu = $event->getMenu();
        $content = $menu
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

        $this->placeAfter($menu, 'content_blocks', 'marketing');
    }

    private function placeAfter(ItemInterface $menu, string $name, string $anchor): void
    {
        $order = array_keys($menu->getChildren());
        if (!in_array($anchor, $order, true)) {
            return;
        }

        $order = array_values(array_diff($order, [$name]));
        array_splice($order, array_search($anchor, $order, true) + 1, 0, [$name]);
        $menu->reorderChildren($order);
    }
}
