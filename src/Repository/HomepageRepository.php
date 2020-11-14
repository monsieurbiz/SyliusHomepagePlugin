<?php

declare(strict_types=1);

namespace MonsieurBiz\SyliusHomepagePlugin\Repository;

use Doctrine\ORM\QueryBuilder;
use MonsieurBiz\SyliusHomepagePlugin\Entity\Homepage\HomepageInterface;
use Sylius\Bundle\ResourceBundle\Doctrine\ORM\EntityRepository;
use Sylius\Component\Core\Model\ChannelInterface;

class HomepageRepository extends EntityRepository
{
    /**
     * @param string $localeCode
     * @return QueryBuilder
     */
    public function createListQueryBuilder(string $localeCode): QueryBuilder
    {
        return $this->createQueryBuilder('o')
            ->addSelect('translation')
            ->leftJoin('o.translations', 'translation', 'WITH', 'translation.locale = :localeCode')
            ->setParameter('localeCode', $localeCode)
        ;
    }

    /**
     * @param ChannelInterface $channel
     * @param string $locale
     * @return HomepageInterface|null
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
