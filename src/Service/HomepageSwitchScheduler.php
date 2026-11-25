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

namespace MonsieurBiz\SyliusHomepagePlugin\Service;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\LockMode;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use MonsieurBiz\SyliusHomepagePlugin\Entity\HomepageInterface;
use MonsieurBiz\SyliusHomepagePlugin\Entity\HomepageSwitch;
use MonsieurBiz\SyliusHomepagePlugin\Entity\HomepageWriteLock;
use MonsieurBiz\SyliusHomepagePlugin\Event\HomepageCacheInvalidation;
use MonsieurBiz\SyliusHomepagePlugin\Exception\HomepageSwitchRejected;
use MonsieurBiz\SyliusHomepagePlugin\Message\ExecuteHomepageSwitch;
use Psr\Clock\ClockInterface;
use Sylius\Component\Channel\Model\ChannelInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Messenger\Transport\Sender\SenderInterface;

final class HomepageSwitchScheduler
{
    /**
     * @param class-string<HomepageInterface> $homepageClass
     */
    public function __construct(
        private EntityManagerInterface $manager,
        private Connection $transportConnection,
        private SenderInterface $sender,
        private ClockInterface $clock,
        private string $homepageClass
    ) {
        if ($this->manager->getConnection() !== $this->transportConnection) {
            throw new \LogicException('The homepage manager and its Messenger transport must use the same default DBAL connection.');
        }
    }

    public function schedule(HomepageInterface $target, ChannelInterface $channel, ?DateTimeImmutable $scheduledAt = null): HomepageSwitch
    {
        return $this->write(function () use ($target, $channel, $scheduledAt): HomepageSwitch {
            $now = $this->clock->now();
            $instant = $scheduledAt ?? $now;
            $this->validateTarget($target);
            $this->validateInstant($channel, $instant, null, null !== $scheduledAt ? $now : null);

            $switch = new HomepageSwitch($channel, $target, $instant);
            $this->manager->persist($switch);
            $this->manager->flush();
            $this->enqueue($switch, $now);

            return $switch;
        });
    }

    public function reschedule(HomepageSwitch $switch, DateTimeImmutable $scheduledAt): void
    {
        $this->write(function () use ($switch, $scheduledAt): void {
            $current = $this->requirePending($switch);
            $now = $this->clock->now();
            $this->validateInstant($current->getChannel(), $scheduledAt, $current->getId(), $now);

            $current->reschedule($scheduledAt);
            $this->manager->flush();
            $this->enqueue($current, $now);
        });
    }

    public function cancel(HomepageSwitch $switch): void
    {
        $this->write(function () use ($switch): void {
            $this->requirePending($switch)->close(HomepageSwitch::CANCELLED);
            $this->manager->flush();
        });
    }

    public function execute(ExecuteHomepageSwitch $message): void
    {
        $this->write(function () use ($message): void {
            $switch = $this->findFresh($message->switchId);
            if (null === $switch || !$switch->isPending() || $message->schedulingKey !== $switch->getSchedulingKey()) {
                return;
            }

            $now = $this->clock->now();
            if ($switch->getScheduledAt() > $now) {
                $this->enqueue($switch, $now);

                return;
            }

            $channel = $switch->getChannel();
            /** @var non-empty-list<HomepageSwitch> $due */
            $due = $this->manager->createQueryBuilder()
                ->select('s')
                ->from(HomepageSwitch::class, 's')
                ->where('s.channel = :channel AND s.status = :pending AND s.scheduledAt <= :now')
                ->orderBy('s.scheduledAt', 'DESC')
                ->setParameter('channel', $channel)
                ->setParameter('pending', HomepageSwitch::PENDING)
                ->setParameter('now', HomepageSwitch::timestamp($now), ParameterType::INTEGER)
                ->getQuery()
                ->setLockMode(LockMode::PESSIMISTIC_WRITE)
                ->setHint(Query::HINT_REFRESH, true)
                ->getResult()
            ;

            $latest = array_shift($due);
            if ($this->transportConnection->fetchOne(
                'SELECT 1 FROM monsieurbiz_homepage_switch WHERE channel_id = ? AND status = ? AND scheduled_at > ? LIMIT 1 FOR UPDATE',
                [$channel->getId(), HomepageSwitch::EXECUTED, HomepageSwitch::timestamp($latest->getScheduledAt())],
                [ParameterType::INTEGER, ParameterType::STRING, ParameterType::INTEGER]
            )) {
                $latest->close(HomepageSwitch::SUPERSEDED, 'newer_switch');
            } else {
                $this->apply($latest);
            }

            foreach ($due as $older) {
                $older->close(HomepageSwitch::SUPERSEDED, 'newer_switch');
            }

            $this->manager->flush();
        });
    }

    private function apply(HomepageSwitch $switch): void
    {
        $channelId = (int) $switch->getChannel()->getId();
        $targetId = (int) $switch->getTarget()->getId();

        // Lock the parent before its assignments; the FK also blocks new locale assignments.
        $channel = $this->transportConnection->fetchAssociative(
            'SELECT code, default_locale_id FROM sylius_channel WHERE id = ? FOR UPDATE',
            [$channelId]
        );
        if (false === $channel) {
            throw new \LogicException('Homepage switch refers to a missing channel.');
        }

        $previousId = $this->transportConnection->fetchOne(
            'SELECT page_id FROM monsieurbiz_homepage_homepage_channels WHERE channel_id = ? FOR UPDATE',
            [$channelId]
        );
        if (false !== $previousId && (int) $previousId === $targetId) {
            $switch->close(HomepageSwitch::NO_CHANGE, 'already_active');

            return;
        }

        if (!$this->targetEnabled($targetId)) {
            $switch->close(HomepageSwitch::FAILED, 'target_disabled');

            return;
        }

        // Lock locale rows and existing assignments against edits or removal until commit.
        /** @var list<string> $locales */
        $locales = $this->transportConnection->fetchFirstColumn(
            'SELECT l.code FROM sylius_channel_locales cl
             INNER JOIN sylius_locale l ON l.id = cl.locale_id
             WHERE cl.channel_id = ? LOCK IN SHARE MODE',
            [$channelId]
        );
        // Include the default even when it is not in the channel locale collection.
        $defaultLocale = $this->transportConnection->fetchOne(
            'SELECT code FROM sylius_locale WHERE id = ? LOCK IN SHARE MODE',
            [$channel['default_locale_id']]
        );
        if (false !== $defaultLocale && !in_array($defaultLocale, $locales, true)) {
            $locales[] = $defaultLocale;
        }

        // The locked page prevents new translations via its FK; lock the content relied on as well.
        $available = $this->transportConnection->fetchFirstColumn(
            "SELECT locale FROM monsieurbiz_homepage_homepage_translation WHERE translatable_id = ? AND TRIM(content) <> '' LOCK IN SHARE MODE",
            [$targetId]
        );

        if (false === $defaultLocale || [] !== array_diff($locales, $available)) {
            $switch->close(HomepageSwitch::FAILED, 'missing_locale');

            return;
        }

        $executedAt = $this->clock->now();
        $previous = null;
        $assignment = ['channel_id' => $channelId];
        if (false === $previousId) {
            $this->transportConnection->insert('monsieurbiz_homepage_homepage_channels', $assignment + ['page_id' => $targetId]);
        } else {
            $previous = $this->manager->getReference($this->homepageClass, (int) $previousId);
            $this->transportConnection->update('monsieurbiz_homepage_homepage_channels', ['page_id' => $targetId], $assignment);
        }

        $switch->recordExecution($previous, $executedAt);
        $this->sender->send(new Envelope(new HomepageCacheInvalidation($channelId, (string) $channel['code'], $locales)));
    }

    private function findFresh(int $id): ?HomepageSwitch
    {
        return $this->manager->createQueryBuilder()
            ->select('s')
            ->from(HomepageSwitch::class, 's')
            ->where('s.id = :id')
            ->setParameter('id', $id)
            ->getQuery()
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
            ->setHint(Query::HINT_REFRESH, true)
            ->getOneOrNullResult()
        ;
    }

    private function requirePending(HomepageSwitch $switch): HomepageSwitch
    {
        $current = $this->findFresh((int) $switch->getId());
        if (null === $current) {
            throw new HomepageSwitchRejected('missing');
        }
        if (!$current->isPending()) {
            throw new HomepageSwitchRejected('not_pending');
        }

        return $current;
    }

    private function validateTarget(HomepageInterface $target): void
    {
        if (!$this->targetEnabled((int) $target->getId())) {
            throw new HomepageSwitchRejected('disabled');
        }
    }

    private function targetEnabled(int $targetId): bool
    {
        return (bool) $this->transportConnection->fetchOne('SELECT enabled FROM monsieurbiz_homepage_homepage WHERE id = ? FOR UPDATE', [$targetId]);
    }

    private function validateInstant(ChannelInterface $channel, DateTimeImmutable $instant, ?int $except, ?DateTimeImmutable $now): void
    {
        if (null !== $now && $instant <= $now) {
            throw new HomepageSwitchRejected('future');
        }
        if ($this->transportConnection->fetchOne(
            'SELECT 1 FROM monsieurbiz_homepage_switch WHERE channel_id = ? AND scheduled_at = ? AND id <> ? FOR UPDATE',
            [$channel->getId(), HomepageSwitch::timestamp($instant), $except ?? 0],
            [ParameterType::INTEGER, ParameterType::INTEGER, ParameterType::INTEGER]
        )) {
            throw new HomepageSwitchRejected('duplicate');
        }
    }

    private function enqueue(HomepageSwitch $switch, DateTimeImmutable $now): void
    {
        // Some Doctrine transport versions truncate sub-second delays; round up and recheck on delivery.
        $remaining = HomepageSwitch::timestamp($switch->getScheduledAt()) - HomepageSwitch::timestamp($now);
        $delay = $remaining > 0 ? intdiv($remaining + 999_999, 1_000_000) * 1000 : 0;
        $this->sender->send(new Envelope(
            new ExecuteHomepageSwitch((int) $switch->getId(), $switch->getSchedulingKey()),
            [new DelayStamp($delay)]
        ));
    }

    /**
     * @template T
     *
     * @param callable(): T $write
     *
     * @return T
     */
    private function write(callable $write): mixed
    {
        try {
            return HomepageWriteLock::run($this->transportConnection, $write);
        } catch (HomepageSwitchRejected $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            // ORM flush can complete before a queue insert fails; do not retain rolled-back entities.
            $this->manager->clear();

            throw $exception;
        }
    }
}
