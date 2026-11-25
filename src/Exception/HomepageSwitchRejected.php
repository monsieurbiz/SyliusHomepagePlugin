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

namespace MonsieurBiz\SyliusHomepagePlugin\Exception;

final class HomepageSwitchRejected extends \DomainException
{
    public function __construct(string $reason)
    {
        parent::__construct('monsieurbiz_homepage.ui.switch.error.' . $reason);
    }
}
