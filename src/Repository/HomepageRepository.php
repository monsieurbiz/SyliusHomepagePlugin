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

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\QueryBuilder;
use MonsieurBiz\SyliusHomepagePlugin\Entity\HomepageInterface;
use Sylius\Bundle\ResourceBundle\Doctrine\ORM\EntityRepository;
use Sylius\Component\Channel\Model\ChannelInterface as BaseChannelInterface;
use Sylius\Component\Core\Model\ChannelInterface;

class HomepageRepository extends EntityRepository
{
    /** @return list<int> */
    public function findAssignedChannelIds(): array
    {
        return array_map(
            intval(...),
            $this->getEntityManager()->getConnection()->fetchFirstColumn('SELECT channel_id FROM monsieurbiz_homepage_homepage_channels')
        );
    }

    /** @param list<int> $switchIds */
    public function findAssignmentError(HomepageInterface $page, array $switchIds = []): ?string
    {
        $connection = $this->getEntityManager()->getConnection();
        $desired = $page->getChannels()->map(static fn (BaseChannelInterface $channel): int => (int) $channel->getId())->toArray();
        $current = null === $page->getId() ? [] : $connection->fetchFirstColumn(
            'SELECT channel_id FROM monsieurbiz_homepage_homepage_channels WHERE page_id = ?',
            [$page->getId()]
        );
        if ([] !== array_diff($current, $desired) || [] !== array_diff($switchIds, $desired)) {
            return 'monsieurbiz_homepage.switch.assigned_channel';
        }
        if ([] !== $desired && method_exists($page, 'isEnabled') && !$page->isEnabled()) {
            return 'monsieurbiz_homepage.switch.assigned_page';
        }

        $added = array_values(array_diff($desired, $current));
        $occupied = [] === $added ? [] : $connection->fetchFirstColumn(
            'SELECT channel_id FROM monsieurbiz_homepage_homepage_channels WHERE channel_id IN (?) FOR UPDATE',
            [$added],
            [ArrayParameterType::INTEGER]
        );

        return [] !== array_diff($occupied, $switchIds) || [] !== array_diff($switchIds, $occupied)
            ? 'monsieurbiz_homepage.switch.assigned_channel'
            : null;
    }

    public function createListQueryBuilder(string $localeCode): QueryBuilder
    {
        return $this->createQueryBuilder('o')
            ->addSelect('translation')
            ->leftJoin('o.translations', 'translation', 'WITH', 'translation.locale = :localeCode')
            ->setParameter('localeCode', $localeCode)
        ;
    }

    /**
     * @throws \Doctrine\ORM\NonUniqueResultException
     */
    public function findOneByChannelAndLocale(ChannelInterface $channel, string $locale): ?HomepageInterface
    {
        return $this->createQueryBuilder('o')
            ->addSelect('translation')
            ->innerJoin('o.translations', 'translation', 'WITH', 'translation.locale = :locale')
            ->andWhere(':channel MEMBER OF o.channels')
            ->addOrderBy('o.createdAt', 'DESC')
            ->setParameter('channel', $channel)
            ->setParameter('locale', $locale)
            ->getQuery()
            ->getOneOrNullResult()
        ;
    }
}
