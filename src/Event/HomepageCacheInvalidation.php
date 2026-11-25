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

namespace MonsieurBiz\SyliusHomepagePlugin\Event;

final class HomepageCacheInvalidation
{
    /**
     * @param list<string> $localeCodes
     */
    public function __construct(
        public readonly int $channelId,
        public readonly string $channelCode,
        public readonly array $localeCodes
    ) {
    }
}
