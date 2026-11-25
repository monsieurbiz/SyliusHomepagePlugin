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

namespace MonsieurBiz\SyliusHomepagePlugin\Repository;

use Doctrine\ORM\QueryBuilder;
use MonsieurBiz\SyliusHomepagePlugin\Entity\HomepageSwitch;
use Sylius\Bundle\ResourceBundle\Doctrine\ORM\EntityRepository;

class HomepageSwitchRepository extends EntityRepository
{
    public function createListQueryBuilder(): QueryBuilder
    {
        return $this->createGridQueryBuilder()
            ->orderBy('o.scheduledAt', 'DESC')
            ->addOrderBy('o.id', 'DESC')
        ;
    }

    public function createHistoryQueryBuilder(): QueryBuilder
    {
        return $this->createGridQueryBuilder()
            ->andWhere('o.status = :executedStatus')
            ->setParameter('executedStatus', HomepageSwitch::EXECUTED)
            ->orderBy('o.executedAt', 'DESC')
            ->addOrderBy('o.id', 'DESC')
        ;
    }

    private function createGridQueryBuilder(): QueryBuilder
    {
        return $this->createQueryBuilder('o')
            ->addSelect('channel', 'target', 'previous', 'targetTranslation', 'previousTranslation')
            ->innerJoin('o.channel', 'channel')
            ->innerJoin('o.target', 'target')
            ->leftJoin('o.previous', 'previous')
            ->leftJoin('target.translations', 'targetTranslation')
            ->leftJoin('previous.translations', 'previousTranslation')
        ;
    }
}
