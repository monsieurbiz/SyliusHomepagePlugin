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

namespace MonsieurBiz\SyliusHomepagePlugin\Entity;

use Doctrine\DBAL\Connection;

class HomepageWriteLock
{
    private int $id = 1;

    public function getId(): int
    {
        return $this->id;
    }

    /**
     * @template T
     *
     * @param callable(): T $write
     *
     * @return T
     */
    public static function run(Connection $connection, callable $write): mixed
    {
        return $connection->transactional(static function (Connection $connection) use ($write) {
            // All writers take the same lock before reading; a page can be shared by several channels.
            $connection->executeStatement('INSERT INTO monsieurbiz_homepage_write_lock (id) VALUES (1) ON DUPLICATE KEY UPDATE id = id');

            return $write();
        });
    }
}
