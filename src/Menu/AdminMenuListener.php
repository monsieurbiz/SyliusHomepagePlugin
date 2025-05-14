<?php

/*
 * This file is part of Monsieur Biz' Homepage plugin for Sylius.
 *
 * (c) Monsieur Biz <sylius@monsieurbiz.com>
 *
 * For the full copyright and license information, please view the LICENSE.txt
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MonsieurBiz\SyliusHomepagePlugin\Menu;

use Sylius\Bundle\UiBundle\Menu\Event\MenuBuilderEvent;

final class AdminMenuListener
{
    public function addAdminMenuItem(MenuBuilderEvent $event): void
    {
        $menu = $event->getMenu();

        if (!$content = $menu->getChild('monsieurbiz-cms')) {
            $content = $menu
                ->addChild('monsieurbiz-cms')
                ->setLabel('monsieurbiz_homepage.ui.cms_content')
                ->setLabelAttribute('icon', 'tabler:file')
                ->setExtra('always_open', true)
            ;
        }

        $content
            ->addChild('monsieurbiz-homepage-homepage', ['route' => 'monsieurbiz_homepage_admin_homepage_index', 'extras' => ['routes' => [
                'monsieurbiz_homepage_admin_homepage_create',
                'monsieurbiz_homepage_admin_homepage_update',
            ]]])
            ->setLabel('monsieurbiz_homepage.ui.homepages')
            ->setLabelAttribute('icon', 'tabler:home')
        ;
    }
}
