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

namespace MonsieurBiz\SyliusHomepagePlugin\MessageHandler;

use MonsieurBiz\SyliusHomepagePlugin\Event\HomepageCacheInvalidation;
use MonsieurBiz\SyliusHomepagePlugin\Message\ExecuteHomepageSwitch;
use MonsieurBiz\SyliusHomepagePlugin\Service\HomepageSwitchScheduler;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

final class HomepageSwitchHandler
{
    public function __construct(
        private HomepageSwitchScheduler $scheduler,
        private EventDispatcherInterface $dispatcher
    ) {
    }

    #[AsMessageHandler(fromTransport: 'monsieurbiz_homepage_switch')]
    public function execute(ExecuteHomepageSwitch $message): void
    {
        $this->scheduler->execute($message);
    }

    #[AsMessageHandler(fromTransport: 'monsieurbiz_homepage_switch')]
    public function invalidateCache(HomepageCacheInvalidation $message): void
    {
        // Listeners must invalidate idempotently: a failed listener or lost ACK causes redelivery.
        $this->dispatcher->dispatch($message);
    }
}
