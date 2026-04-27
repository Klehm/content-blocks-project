<?php

declare(strict_types=1);

namespace App\Menu;

use Knp\Menu\FactoryInterface;
use Knp\Menu\ItemInterface;

final class AdminMenuBuilder
{
    public function __construct(
        private readonly FactoryInterface $factory,
    ) {
    }

    /**
     * @param array<string, mixed> $options
     */
    public function createMenu(array $options = []): ItemInterface
    {
        $menu = $this->factory->createItem('root');

        $content = $menu->addChild('content', [
            'label' => 'Content',
        ]);
        $content->setLabelAttribute('icon', 'tabler:file-text');
        $content->setExtra('always_open', true);

        $content->addChild('pages', [
            'label' => 'Pages',
            'route' => 'app_admin_page_index',
        ]);

        return $menu;
    }
}
