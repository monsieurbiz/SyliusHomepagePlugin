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

namespace MonsieurBiz\SyliusHomepagePlugin\Tests\Integration;

use App\Kernel;
use DateTime;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\LockWaitTimeoutException;
use Doctrine\DBAL\TransactionIsolationLevel;
use Doctrine\ORM\EntityManagerInterface;
use MonsieurBiz\SyliusHomepagePlugin\Entity\Homepage;
use MonsieurBiz\SyliusHomepagePlugin\Entity\HomepageSwitch;
use MonsieurBiz\SyliusHomepagePlugin\Entity\HomepageTranslation;
use MonsieurBiz\SyliusHomepagePlugin\Event\HomepageCacheInvalidation;
use MonsieurBiz\SyliusHomepagePlugin\Exception\HomepageSwitchRejected;
use MonsieurBiz\SyliusHomepagePlugin\Message\ExecuteHomepageSwitch;
use MonsieurBiz\SyliusHomepagePlugin\Repository\HomepageRepository;
use MonsieurBiz\SyliusHomepagePlugin\Service\HomepageSwitchScheduler;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Clock\ClockInterface;
use RuntimeException;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Currency\Model\CurrencyInterface;
use Sylius\Component\Locale\Model\LocaleInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Messenger\Transport\Receiver\ListableReceiverInterface;
use Symfony\Component\Messenger\Transport\Sender\SenderInterface;

final class HomepageSwitchTest extends KernelTestCase
{
    private const TRANSPORT = 'monsieurbiz_homepage_switch';

    private EntityManagerInterface $manager;

    private Connection $observer;

    private MockClock $clock;

    private HomepageSwitchScheduler $scheduler;

    protected static function getKernelClass(): string
    {
        return Kernel::class;
    }

    protected function setUp(): void
    {
        self::assertSame('1', getenv('HOMEPAGE_SWITCH_QA'), 'Use a dedicated, migrated database for this integration suite.');
        self::bootKernel(['environment' => 'test', 'debug' => false]);
        $container = self::getContainer();

        $this->clock = new MockClock('now', 'UTC');
        $container->set(ClockInterface::class, $this->clock);
        $this->manager = $container->get('doctrine.orm.entity_manager');
        $connection = $this->manager->getConnection();
        self::assertMatchesRegularExpression('/_(?:qa|test)$/D', $connection->getParams()['dbname'] ?? '', 'The integration suite only writes to a dedicated DB ending in _qa or _test.');
        $this->observer = DriverManager::getConnection($connection->getParams());
        $this->scheduler = $container->get(HomepageSwitchScheduler::class);
    }

    protected function tearDown(): void
    {
        if (isset($this->observer)) {
            $this->observer->close();
        }

        parent::tearDown();
    }

    public function testDelayedRequestAndQueueEntryRollBackTogether(): void
    {
        [$channel] = $this->channels();
        $current = $this->page('Winter', $channel);
        $target = $this->page('Sale');
        $this->manager->flush();

        $connection = $this->manager->getConnection();
        $lastMessageId = (int) $connection->fetchOne('SELECT MAX(id) FROM monsieurbiz_homepage_switch_messages');
        $scheduledAt = $this->clock->now()->modify('+21 days')->setTimezone(new DateTimeZone('Pacific/Auckland'));
        $connection->beginTransaction();

        try {
            $switch = $this->scheduler->schedule($target, $channel, $scheduledAt);
            $id = $switch->getId();
            $queueEntry = $connection->fetchAssociative(
                'SELECT id, created_at, available_at FROM monsieurbiz_homepage_switch_messages WHERE id > ?',
                [$lastMessageId],
            );
            self::assertIsArray($queueEntry);
            $messageId = $queueEntry['id'];
            $delay = strtotime($queueEntry['available_at'] . ' UTC') - strtotime($queueEntry['created_at'] . ' UTC');
            self::assertEqualsWithDelta(21 * 86400, $delay, 1);
            self::assertSame($scheduledAt->format('U.u'), $switch->getScheduledAt()->format('U.u'));
            self::assertSame('UTC', $switch->getScheduledAt()->getTimezone()->getName());
            self::assertSame(1, (int) $connection->fetchOne('SELECT COUNT(*) FROM monsieurbiz_homepage_switch WHERE id = ?', [$id]));
            self::assertFalse($this->observer->fetchOne('SELECT id FROM monsieurbiz_homepage_switch WHERE id = ?', [$id]));
            self::assertFalse($this->observer->fetchOne('SELECT id FROM monsieurbiz_homepage_switch_messages WHERE id = ?', [$messageId]));
            self::assertSame($current->getId(), $this->assignedPageId($channel));
        } finally {
            $connection->rollBack();
            $this->manager->clear();
        }

        self::assertFalse($this->observer->fetchOne('SELECT id FROM monsieurbiz_homepage_switch WHERE id = ?', [$id]));
        self::assertFalse($this->observer->fetchOne('SELECT id FROM monsieurbiz_homepage_switch_messages WHERE id = ?', [$messageId]));
    }

    public function testTransportFailureLeavesNoOrphanSwitch(): void
    {
        [$channel] = $this->channels();
        $target = $this->page('Sale');
        $this->manager->flush();
        $queueHead = $this->lastMessageId();

        $failure = new RuntimeException('Simulated failure after transport insert');
        $sender = new class (self::getContainer()->get('messenger.transport.' . self::TRANSPORT), $failure) implements SenderInterface {
            public function __construct(private SenderInterface $transport, private RuntimeException $failure)
            {
            }

            public function send(Envelope $envelope): Envelope
            {
                $this->transport->send($envelope);

                throw $this->failure;
            }
        };
        $scheduler = new HomepageSwitchScheduler($this->manager, $this->manager->getConnection(), $sender, $this->clock, Homepage::class);

        try {
            $scheduler->schedule($target, $channel, $this->clock->now()->modify('+1 hour'));
            self::fail('The send failure must abort scheduling.');
        } catch (RuntimeException $exception) {
            self::assertSame($failure, $exception);
        }

        self::assertFalse($this->observer->fetchOne('SELECT id FROM monsieurbiz_homepage_switch WHERE channel_id = ?', [$channel->getId()]));
        self::assertSame($queueHead, $this->lastMessageId());
    }

    public function testTheSameInstantCannotBeScheduledTwiceOnOneChannel(): void
    {
        [$a, $b] = $this->channels();
        $target = $this->page('Sale');
        $this->manager->flush();
        $instant = $this->clock->now()->modify('+1 hour');
        $differentOffset = $instant->setTimezone(new DateTimeZone('Pacific/Auckland'));
        $this->scheduler->schedule($target, $a, $instant);
        $this->scheduler->schedule($target, $b, $differentOffset);
        $queueHead = $this->lastMessageId();

        try {
            $this->scheduler->schedule($target, $a, $differentOffset);
            self::fail('A second request for the same channel and instant must be rejected.');
        } catch (HomepageSwitchRejected $exception) {
            self::assertStringEndsWith('.duplicate', $exception->getMessage());
        }
        self::assertSame(1, (int) $this->observer->fetchOne('SELECT COUNT(*) FROM monsieurbiz_homepage_switch WHERE channel_id = ?', [$a->getId()]));
        self::assertSame($queueHead, $this->lastMessageId());
    }

    public function testStaleCancelledAndDuplicateMessagesCannotAffectAnotherChannel(): void
    {
        [$a, $b] = $this->channels();
        $current = $this->page('Winter', $a, $b);
        $target = $this->page('Sale');
        $cancelledTarget = $this->page('Spring');
        $this->manager->flush();

        $switch = $this->scheduler->schedule($target, $a, $this->clock->now()->modify('+1 hour'));
        $stale = $this->message($switch);
        $this->scheduler->reschedule($switch, $this->clock->now()->modify('+3 hours'));
        $valid = $this->message($switch);
        self::assertNotSame($stale->schedulingKey, $valid->schedulingKey);

        $cancelled = $this->scheduler->schedule($cancelledTarget, $a, $this->clock->now()->modify('+2 hours'));
        $cancelledMessage = $this->message($cancelled);
        $this->scheduler->cancel($cancelled);

        $this->clock->modify('+2 hours');
        $this->scheduler->execute($stale);
        $this->scheduler->execute($cancelledMessage);
        self::assertSame('pending', $this->state($switch)['status']);
        self::assertSame('cancelled', $this->state($cancelled)['status']);
        self::assertSame($current->getId(), $this->assignedPageId($a));

        $this->clock->modify('+1 hour');
        $executionTime = $this->clock->now()->format('U.u');
        $this->scheduler->execute($valid);
        $this->clock->modify('+1 hour');
        $this->scheduler->execute($valid);

        $this->manager->clear();
        $executed = $this->manager->find(HomepageSwitch::class, $valid->switchId);
        self::assertSame('executed', $executed->getStatus());
        self::assertSame($executionTime, $executed->getExecutedAt()->format('U.u'));
        self::assertSame($current->getId(), $executed->getPrevious()->getId());
        self::assertSame($target->getId(), $executed->getTarget()->getId());
        self::assertSame($target->getId(), $this->assignedPageId($a));
        self::assertSame($current->getId(), $this->assignedPageId($b));

        /** @var HomepageRepository $repository */
        $repository = self::getContainer()->get('monsieurbiz_homepage.repository.homepage');
        $a = $this->manager->find($a::class, $a->getId());
        foreach (['en_US', 'fr_FR'] as $locale) {
            self::assertSame($target->getId(), $repository->findOneByChannelAndLocale($a, $locale)?->getId());
        }
    }

    public function testOldestMessageCannotWinAfterWorkerDowntime(): void
    {
        [$channel] = $this->channels();
        $current = $this->page('Winter', $channel);
        $olderPage = $this->page('Sale');
        $newerPage = $this->page('Spring');
        $futurePage = $this->page('Summer');
        $this->manager->flush();

        $older = $this->scheduler->schedule($olderPage, $channel, $this->clock->now()->modify('+1 hour'));
        $newer = $this->scheduler->schedule($newerPage, $channel, $this->clock->now()->modify('+2 hours'));
        $future = $this->scheduler->schedule($futurePage, $channel, $this->clock->now()->modify('+4 hours'));

        $this->clock->modify('+3 hours');
        $this->scheduler->execute($this->message($older));
        self::assertNotSame($olderPage->getId(), $this->assignedPageId($channel));
        $this->scheduler->execute($this->message($newer));
        $this->scheduler->execute($this->message($older));

        self::assertSame('superseded', $this->state($older)['status']);
        self::assertSame('newer_switch', $this->state($older)['reason']);
        self::assertSame('executed', $this->state($newer)['status']);
        self::assertSame($current->getId(), (int) $this->state($newer)['previous_id']);
        self::assertSame('pending', $this->state($future)['status']);
        self::assertSame($newerPage->getId(), $this->assignedPageId($channel));
    }

    #[DataProvider('targetNoLongerUsable')]
    public function testUnusableTargetDoesNotDisplaceCurrentPage(string $reason): void
    {
        [$channel] = $this->channels();
        $current = $this->page('Winter', $channel);
        $target = $this->page('Sale');
        $this->manager->flush();

        $switch = $this->scheduler->schedule($target, $channel, $this->clock->now()->modify('+1 hour'));
        if ('target_disabled' === $reason) {
            $target->setEnabled(false);
        } else {
            $target->removeTranslation($target->getTranslation('fr_FR'));
        }
        $this->manager->flush();

        $queueHead = $this->lastMessageId();
        $this->clock->modify('+1 hour');
        $this->scheduler->execute($this->message($switch));

        self::assertSame('failed', $this->state($switch)['status']);
        self::assertSame($reason, $this->state($switch)['reason']);
        self::assertSame($current->getId(), $this->assignedPageId($channel));
        self::assertSame($queueHead, $this->lastMessageId());
    }

    public static function targetNoLongerUsable(): iterable
    {
        yield 'disabled after scheduling' => ['target_disabled'];
        yield 'missing active locale after scheduling' => ['missing_locale'];
    }

    #[DataProvider('transactionIsolationLevels')]
    public function testLocaleAssignmentsCannotChangeBeforeTheSwitchCommits(int $isolation): void
    {
        [$channel] = $this->channels();
        $current = $this->page('Winter', $channel);
        $target = $this->page('Sale');
        $extraLocale = $this->locale('es_ES');
        $this->manager->flush();
        $message = $this->message($this->scheduler->schedule($target, $channel));
        $association = ['channel_id' => $channel->getId(), 'locale_id' => $extraLocale->getId()];

        $connection = $this->manager->getConnection();
        $connection->setTransactionIsolation($isolation);
        $this->observer->setTransactionIsolation($isolation);
        $this->observer->executeStatement('SET SESSION innodb_lock_wait_timeout = 1');
        $connection->beginTransaction();
        $this->observer->beginTransaction();
        try {
            $this->scheduler->execute($message);
            self::assertSame('executed', $connection->fetchOne('SELECT status FROM monsieurbiz_homepage_switch WHERE id = ?', [$message->switchId]));
            self::assertSame($current->getId(), $this->assignedPageId($channel));

            try {
                $this->observer->insert('sylius_channel_locales', $association);
                self::fail('A concurrent new locale crossed the switch transaction.');
            } catch (LockWaitTimeoutException) {
                $connection->commit();
            }
        } finally {
            $this->observer->rollBack();
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
        }

        self::assertSame($target->getId(), $this->assignedPageId($channel));
        $this->observer->beginTransaction();
        try {
            self::assertSame(1, $this->observer->insert('sylius_channel_locales', $association));
        } finally {
            $this->observer->rollBack();
        }
    }

    public static function transactionIsolationLevels(): iterable
    {
        yield 'READ COMMITTED' => [TransactionIsolationLevel::READ_COMMITTED];
        yield 'REPEATABLE READ' => [TransactionIsolationLevel::REPEATABLE_READ];
    }

    public function testAdminCanCreateAHomepageOnlyOnAnUnassignedChannel(): void
    {
        [$channel, $freeChannel] = $this->channels();
        $current = $this->page('Winter', $channel);
        $admin = self::getContainer()->get('sylius.factory.admin_user')->createNew();
        $admin->setUsername('qa_' . bin2hex(random_bytes(8)));
        $admin->setEmail($admin->getUsername() . '@example.invalid');
        $admin->setPassword('qa-placeholder');
        $admin->setLocaleCode('en_US');
        $admin->setEnabled(true);
        $this->manager->persist($admin);
        $this->manager->flush();

        $client = new KernelBrowser(self::$kernel);
        $client->loginUser($admin, 'admin');
        $router = self::getContainer()->get('router');
        $url = $router->generate('monsieurbiz_homepage_admin_homepage_create');
        $form = $client->request('GET', $url);
        self::assertSame(200, $client->getResponse()->getStatusCode());
        $token = $form->filter('input[name="monsieurbiz_homepage_homepage[_token]"]')->attr('value');
        $pageCount = (int) $this->observer->fetchOne('SELECT COUNT(*) FROM monsieurbiz_homepage_homepage');

        $payload = [
            '_token' => $token,
            'channels' => [$channel->getCode()],
            'enabled' => '1',
            'translations' => ['en_US' => ['name' => 'Sale', 'content' => '[]']],
        ];
        $client->request('POST', $url, ['monsieurbiz_homepage_homepage' => $payload]);

        self::assertSame(303, $client->getResponse()->getStatusCode());
        self::assertSame($url, $client->getResponse()->headers->get('Location'));
        $result = $client->followRedirect();
        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertStringContainsString('schedule a switch to its replacement', $result->text());
        self::assertSame($pageCount, (int) $this->observer->fetchOne('SELECT COUNT(*) FROM monsieurbiz_homepage_homepage'));
        self::assertSame($current->getId(), $this->assignedPageId($channel));

        $form = $client->request('GET', $url);
        $payload['_token'] = $form->filter('input[name="monsieurbiz_homepage_homepage[_token]"]')->attr('value');
        $payload['channels'] = [$freeChannel->getCode()];
        $client->request('POST', $url, ['monsieurbiz_homepage_homepage' => $payload]);

        $created = $this->assignedPageId($freeChannel);
        self::assertNotNull($created, 'The new page serves the previously unassigned channel.');
        self::assertNotSame($current->getId(), $created);
        self::assertSame($pageCount + 1, (int) $this->observer->fetchOne('SELECT COUNT(*) FROM monsieurbiz_homepage_homepage'));
        self::assertSame($current->getId(), $this->assignedPageId($channel));
        self::assertSame(302, $client->getResponse()->getStatusCode());
        self::assertSame($router->generate('monsieurbiz_homepage_admin_homepage_update', ['id' => $created]), $client->getResponse()->headers->get('Location'));
    }

    public function testFailedInvalidationRetriesWithoutChangingAssignmentOrHistory(): void
    {
        [$channel, $otherChannel] = $this->channels();
        $current = $this->page('Winter', $channel, $otherChannel);
        $target = $this->page('Sale');
        $this->manager->flush();
        $switch = $this->scheduler->schedule($target, $channel);
        self::assertSame($current->getId(), $this->assignedPageId($channel));
        self::assertSame('pending', $this->state($switch)['status']);

        $calls = 0;
        $failure = new RuntimeException('Cache unavailable');
        $warmCache = [
            $channel->getId() => ['en_US' => 'old A en', 'fr_FR' => 'old A fr'],
            $otherChannel->getId() => ['en_US' => 'old B en', 'fr_FR' => 'old B fr'],
        ];
        $originalCache = $warmCache;
        /** @var EventDispatcherInterface $dispatcher */
        $dispatcher = self::getContainer()->get('event_dispatcher');
        $dispatcher->addListener(HomepageCacheInvalidation::class, function (HomepageCacheInvalidation $event) use ($channel, $target, $failure, &$calls, &$warmCache): void {
            self::assertSame($channel->getId(), $event->channelId);
            self::assertSame($channel->getCode(), $event->channelCode);
            self::assertEqualsCanonicalizing(['en_US', 'fr_FR'], $event->localeCodes);
            self::assertSame($target->getId(), $this->assignedPageId($channel));
            if (1 === ++$calls) {
                throw $failure;
            }
            foreach ($event->localeCodes as $locale) {
                unset($warmCache[$event->channelId][$locale]);
            }
        });

        $messageId = $this->lastMessageId();
        $message = $this->message($switch);
        $this->scheduler->execute($message);
        $invalidationId = $this->lastMessageId();
        self::assertGreaterThan($messageId, $invalidationId);
        self::assertSame(0, $calls, 'Invalidation is queued, not run inline with the switch.');

        /** @var ListableReceiverInterface $receiver */
        $receiver = self::getContainer()->get('messenger.transport.' . self::TRANSPORT);
        $envelope = $receiver->find((string) $invalidationId);
        self::assertNotNull($envelope);
        self::assertInstanceOf(HomepageCacheInvalidation::class, $envelope->getMessage());
        $envelope = $envelope->with(new ReceivedStamp(self::TRANSPORT));
        /** @var MessageBusInterface $bus */
        $bus = self::getContainer()->get('messenger.default_bus');
        try {
            $bus->dispatch($envelope);
            self::fail('A failed invalidation must be retryable.');
        } catch (HandlerFailedException $exception) {
            self::assertSame($failure, $exception->getPrevious());
        }

        $history = $this->state($switch);
        self::assertSame('executed', $history['status']);
        self::assertSame($current->getId(), (int) $history['previous_id']);
        self::assertSame($originalCache, $warmCache);
        $this->clock->modify('+1 hour');
        $this->scheduler->execute($message);
        $bus->dispatch($envelope);

        self::assertSame(2, $calls);
        self::assertSame($invalidationId, $this->lastMessageId(), 'Replaying activation does not enqueue a second invalidation.');
        self::assertSame($history, $this->state($switch));
        self::assertSame($target->getId(), $this->assignedPageId($channel));
        self::assertSame($current->getId(), $this->assignedPageId($otherChannel));
        self::assertSame([], $warmCache[$channel->getId()]);
        self::assertSame($originalCache[$otherChannel->getId()], $warmCache[$otherChannel->getId()]);
    }

    /** @return array{ChannelInterface, ChannelInterface} */
    private function channels(): array
    {
        $container = self::getContainer();
        $locales = [$this->locale('en_US'), $this->locale('fr_FR')];
        $currency = $container->get('sylius.repository.currency')->findOneBy(['code' => 'USD']);
        if (null === $currency) {
            /** @var CurrencyInterface $currency */
            $currency = $container->get('sylius.factory.currency')->createNew();
            $currency->setCode('USD');
            $this->manager->persist($currency);
        }

        $channels = [];
        foreach (['A', 'B'] as $label) {
            /** @var ChannelInterface $channel */
            $channel = $container->get('sylius.factory.channel')->createNew();
            $channel->setCode('QA_' . $label . '_' . bin2hex(random_bytes(4)));
            $channel->setName($label);
            $channel->setBaseCurrency($currency);
            $channel->setDefaultLocale($locales[0]);
            foreach ($locales as $locale) {
                $channel->addLocale($locale);
            }
            $this->manager->persist($channel);
            $channels[] = $channel;
        }

        return $channels;
    }

    private function locale(string $code): LocaleInterface
    {
        $container = self::getContainer();
        $locale = $container->get('sylius.repository.locale')->findOneBy(['code' => $code]);
        if (null === $locale) {
            $locale = $container->get('sylius.factory.locale')->createNew();
            $locale->setCode($code);
            $this->manager->persist($locale);
        }

        return $locale;
    }

    private function page(string $name, ChannelInterface ...$channels): Homepage
    {
        $page = new Homepage();
        $page->setCreatedAt(new DateTimeImmutable());
        $page->setUpdatedAt(new DateTime());
        foreach (['en_US', 'fr_FR'] as $locale) {
            $translation = new HomepageTranslation();
            $translation->setLocale($locale);
            $translation->setName($name);
            $translation->setContent('[]');
            $page->addTranslation($translation);
        }
        foreach ($channels as $channel) {
            $page->addChannel($channel);
        }
        $this->manager->persist($page);

        return $page;
    }

    private function message(HomepageSwitch $switch): ExecuteHomepageSwitch
    {
        return new ExecuteHomepageSwitch($switch->getId(), $switch->getSchedulingKey());
    }

    private function assignedPageId(ChannelInterface $channel): ?int
    {
        $id = $this->observer->fetchOne(
            'SELECT page_id FROM monsieurbiz_homepage_homepage_channels WHERE channel_id = ?',
            [$channel->getId()],
        );

        return false === $id ? null : (int) $id;
    }

    private function state(HomepageSwitch $switch): array
    {
        $row = $this->observer->fetchAssociative(
            'SELECT status, reason, previous_id, executed_at FROM monsieurbiz_homepage_switch WHERE id = ?',
            [$switch->getId()],
        );
        self::assertIsArray($row);

        return $row;
    }

    private function lastMessageId(): int
    {
        return (int) $this->observer->fetchOne('SELECT MAX(id) FROM monsieurbiz_homepage_switch_messages');
    }
}
