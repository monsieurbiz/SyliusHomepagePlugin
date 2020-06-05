<?php
declare(strict_types=1);

namespace MonsieurBiz\SyliusHomepagePlugin\Menu;

use Sylius\Bundle\UiBundle\Menu\Event\MenuBuilderEvent;
use Knp\Menu\Util\MenuManipulator;

final class AdminMenuListener
{
    /**
     * @var MenuManipulator
     */
    private $manipulator;

    /**
     * AdminMenuListener constructor.
     *
     * @param MenuManipulator $manipulator
     */
    public function __construct(MenuManipulator $manipulator)
    {
        $this->manipulator = $manipulator;
    }

    public function addAdminMenuItem(MenuBuilderEvent $event): void
    {
        $menu = $event->getMenu();

        if (!$content = $menu->getChild('mbiz-cms')) {
            $content = $menu
                ->addChild('mbiz-cms')
                ->setLabel('monsieurbiz_homepage.ui.cms_content')
            ;
        }

        $content->addChild('mbiz-homepage-homepage', ['route' => 'monsieurbiz_homepage_admin_homepage_index'])
            ->setLabel('monsieurbiz_homepage.ui.homepages')
            ->setLabelAttribute('icon', 'home')
        ;
        $this->manipulator->moveChildToPosition($menu, $content, 0);
    }

}
