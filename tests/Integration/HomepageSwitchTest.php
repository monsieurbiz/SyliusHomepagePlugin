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
use Sylius\Bundle\ResourceBundle\Controller\AuthorizationCheckerInterface;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Currency\Model\CurrencyInterface;
use Sylius\Component\Locale\Model\LocaleInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\DomCrawler\Crawler;
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

    private const DRAFT_CONTENT = '[{"code":"monsieurbiz.html","data":{"content":"<p>Homepage draft</p>"}}]';

    private EntityManagerInterface $manager;

    private Connection $observer;

    private MockClock $clock;

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
            $switch = $this->scheduler()->schedule($target, $channel, $scheduledAt);
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
        $this->scheduler()->schedule($target, $a, $instant);
        $this->scheduler()->schedule($target, $b, $differentOffset);
        $queueHead = $this->lastMessageId();

        try {
            $this->scheduler()->schedule($target, $a, $differentOffset);
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

        $switch = $this->scheduler()->schedule($target, $a, $this->clock->now()->modify('+1 hour'));
        $stale = $this->message($switch);
        $this->scheduler()->reschedule($switch, $this->clock->now()->modify('+3 hours'));
        $valid = $this->message($switch);
        self::assertNotSame($stale->schedulingKey, $valid->schedulingKey);

        $cancelled = $this->scheduler()->schedule($cancelledTarget, $a, $this->clock->now()->modify('+2 hours'));
        $cancelledMessage = $this->message($cancelled);
        $this->scheduler()->cancel($cancelled);

        $this->clock->modify('+2 hours');
        $this->scheduler()->execute($stale);
        $this->scheduler()->execute($cancelledMessage);
        self::assertSame('pending', $this->state($switch)['status']);
        self::assertSame('cancelled', $this->state($cancelled)['status']);
        self::assertSame($current->getId(), $this->assignedPageId($a));

        $this->clock->modify('+1 hour');
        $executionTime = $this->clock->now()->format('U.u');
        $this->scheduler()->execute($valid);
        $this->clock->modify('+1 hour');
        $this->scheduler()->execute($valid);

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

        $older = $this->scheduler()->schedule($olderPage, $channel, $this->clock->now()->modify('+1 hour'));
        $newer = $this->scheduler()->schedule($newerPage, $channel, $this->clock->now()->modify('+2 hours'));
        $future = $this->scheduler()->schedule($futurePage, $channel, $this->clock->now()->modify('+4 hours'));

        $this->clock->modify('+3 hours');
        $this->scheduler()->execute($this->message($older));
        self::assertNotSame($olderPage->getId(), $this->assignedPageId($channel));
        $this->scheduler()->execute($this->message($newer));
        $this->scheduler()->execute($this->message($older));

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

        $switch = $this->scheduler()->schedule($target, $channel, $this->clock->now()->modify('+1 hour'));
        if ('target_disabled' === $reason) {
            $target->setEnabled(false);
        } else {
            $target->removeTranslation($target->getTranslation('fr_FR'));
        }
        $this->manager->flush();

        $queueHead = $this->lastMessageId();
        $this->clock->modify('+1 hour');
        $this->scheduler()->execute($this->message($switch));

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
        $message = $this->message($this->scheduler()->schedule($target, $channel));
        $association = ['channel_id' => $channel->getId(), 'locale_id' => $extraLocale->getId()];

        $connection = $this->manager->getConnection();
        $connection->setTransactionIsolation($isolation);
        $this->observer->setTransactionIsolation($isolation);
        $this->observer->executeStatement('SET SESSION innodb_lock_wait_timeout = 1');
        $connection->beginTransaction();
        $this->observer->beginTransaction();
        try {
            $this->scheduler()->execute($message);
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

        $client = $this->adminBrowser();
        $router = self::getContainer()->get('router');
        $url = $router->generate('monsieurbiz_homepage_admin_homepage_create');
        $form = $client->request('GET', $url);
        self::assertSame(200, $client->getResponse()->getStatusCode());
        $token = $form->filter('input[name="monsieurbiz_homepage_homepage[_token]"]')->attr('value');
        $pageCount = (int) $this->observer->fetchOne('SELECT COUNT(*) FROM monsieurbiz_homepage_homepage');
        $queueHead = $this->lastMessageId();

        $payload = [
            '_token' => $token,
            'channels' => [$channel->getCode()],
            'enabled' => '1',
            'translations' => ['en_US' => ['name' => 'Sale', 'content' => self::DRAFT_CONTENT]],
        ];
        $result = $client->request('POST', $url, ['monsieurbiz_homepage_homepage' => $payload]);

        self::assertSame(422, $client->getResponse()->getStatusCode());
        self::assertStringContainsString('schedule a switch', $result->filter('form#monsieurbiz_homepage_homepage')->text());
        $this->assertSubmittedTranslation($result, $payload['translations']['en_US']);
        self::assertSame($pageCount, (int) $this->observer->fetchOne('SELECT COUNT(*) FROM monsieurbiz_homepage_homepage'));
        self::assertSame($queueHead, $this->lastMessageId());
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

    public function testCreateCanQueueAnOptedInOccupiedChannelWithoutReassigningItInline(): void
    {
        [$occupied, $free] = $this->channels();
        [$unrelated, $stale] = $this->channels();
        $previous = $this->page('Old homepage', $occupied, $unrelated, $stale);
        $client = $this->adminBrowser();
        $url = '/admin/homepages/new';
        $form = $client->request('GET', $url);
        self::assertSame(200, $client->getResponse()->getStatusCode());
        $optIn = $form->filter('input[name="monsieurbiz_homepage_homepage[switchNow][]"][value="' . $occupied->getCode() . '"]');
        self::assertCount(1, $optIn);
        self::assertNull($optIn->attr('checked'));
        self::assertCount(0, $form->filter('input[name="monsieurbiz_homepage_homepage[switchNow][]"][value="' . $free->getCode() . '"]'));
        self::assertCount(1, $form->filter('input[name="monsieurbiz_homepage_homepage[switchNow][]"][value="' . $stale->getCode() . '"]'));
        self::assertCount(1, $form->filter('input[value="' . $occupied->getCode() . '"][name="monsieurbiz_homepage_homepage[channels][]"][data-homepage-channel-occupied="1"]'));
        $choices = [$occupied->getCode(), $free->getCode()];
        $payload = [
            '_token' => $form->filter('input[name="monsieurbiz_homepage_homepage[_token]"]')->attr('value'),
            'channels' => $choices,
            'enabled' => '1',
            'translations' => array_fill_keys(['en_US', 'fr_FR'], ['name' => 'New homepage', 'content' => self::DRAFT_CONTENT]),
        ];
        $pageCount = (int) $this->observer->fetchOne('SELECT COUNT(*) FROM monsieurbiz_homepage_homepage');
        $queueHead = $this->lastMessageId();

        foreach ([
            [$payload, 'Check the selected channels'],
            [$payload + ['switchNow' => [$unrelated->getCode()]], 'Check the selected channels'],
            [array_diff_key($payload, ['enabled' => true]) + ['switchNow' => [$occupied->getCode()]], 'A homepage assigned to a channel cannot be disabled'],
        ] as [$invalid, $error]) {
            $result = $client->request('POST', $url, ['monsieurbiz_homepage_homepage' => $invalid]);
            self::assertSame(422, $client->getResponse()->getStatusCode());
            self::assertStringContainsString($error, $result->filter('form#monsieurbiz_homepage_homepage')->text());
            $this->assertSubmittedTranslation($result, $payload['translations']['en_US']);
            self::assertSame($pageCount, (int) $this->observer->fetchOne('SELECT COUNT(*) FROM monsieurbiz_homepage_homepage'));
            self::assertSame($queueHead, $this->lastMessageId());
            self::assertSame($previous->getId(), $this->assignedPageId($occupied));
            self::assertNull($this->assignedPageId($free));
        }

        // Simulate a fixture assignment becoming free between displaying and submitting the form.
        $this->observer->delete('monsieurbiz_homepage_homepage_channels', ['channel_id' => $stale->getId()]);
        $result = $client->request('POST', $url, ['monsieurbiz_homepage_homepage' => array_replace($payload, [
            'channels' => [$stale->getCode(), $free->getCode()],
            'switchNow' => [$stale->getCode()],
        ])]);
        self::assertSame(422, $client->getResponse()->getStatusCode());
        self::assertStringContainsString('The selected choice is invalid.', $result->text());
        self::assertSame($pageCount, (int) $this->observer->fetchOne('SELECT COUNT(*) FROM monsieurbiz_homepage_homepage'));
        self::assertSame($queueHead, $this->lastMessageId());
        self::assertSame($previous->getId(), $this->assignedPageId($occupied));
        self::assertNull($this->assignedPageId($stale));
        self::assertNull($this->assignedPageId($free));

        $tokenless = array_replace($payload, ['_token' => 'invalid-qa-token', 'switchNow' => [$occupied->getCode()]]);
        $result = $client->request('POST', $url, ['monsieurbiz_homepage_homepage' => $tokenless]);
        self::assertSame(422, $client->getResponse()->getStatusCode());
        self::assertStringContainsString('The CSRF token is invalid.', $result->text());
        self::assertSame($pageCount, (int) $this->observer->fetchOne('SELECT COUNT(*) FROM monsieurbiz_homepage_homepage'));
        self::assertSame($queueHead, $this->lastMessageId());
        self::assertSame($previous->getId(), $this->assignedPageId($occupied));
        self::assertNull($this->assignedPageId($free));

        $form = $client->request('GET', $url);
        $payload['_token'] = $form->filter('input[name="monsieurbiz_homepage_homepage[_token]"]')->attr('value');
        $client->request('POST', $url, ['monsieurbiz_homepage_homepage' => $payload + ['switchNow' => [$occupied->getCode()]]]);
        $targetId = $this->assignedPageId($free);
        self::assertNotNull($targetId);
        self::assertSame(302, $client->getResponse()->getStatusCode());
        self::assertSame('/admin/homepages/' . $targetId . '/edit', $client->getResponse()->headers->get('Location'));
        $edit = $client->followRedirect();
        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertStringContainsString('Switch request recorded and awaiting processing.', $edit->text());
        self::assertCount(0, $edit->filter('input[name="monsieurbiz_homepage_homepage[switchNow][]"]'));
        self::assertSame($pageCount + 1, (int) $this->observer->fetchOne('SELECT COUNT(*) FROM monsieurbiz_homepage_homepage'));
        self::assertSame($previous->getId(), $this->assignedPageId($occupied));
        $switches = $this->observer->fetchAllAssociative('SELECT id, channel_id, status, previous_id FROM monsieurbiz_homepage_switch WHERE target_id = ?', [$targetId]);
        self::assertCount(1, $switches);
        self::assertSame($occupied->getId(), (int) $switches[0]['channel_id']);
        self::assertSame('pending', $switches[0]['status']);
        self::assertNull($switches[0]['previous_id']);

        $messages = $this->observer->fetchAllAssociative('SELECT id, created_at, available_at FROM monsieurbiz_homepage_switch_messages WHERE id > ?', [$queueHead]);
        self::assertCount(1, $messages);
        self::assertSame($messages[0]['created_at'], $messages[0]['available_at']);
        $container = self::getContainer();
        $envelope = $container->get('messenger.transport.' . self::TRANSPORT)->find((string) $messages[0]['id']);
        self::assertInstanceOf(ExecuteHomepageSwitch::class, $envelope?->getMessage());
        self::assertSame((int) $switches[0]['id'], $envelope->getMessage()->switchId);
        $container->get('messenger.default_bus')->dispatch($envelope->with(new ReceivedStamp(self::TRANSPORT)));

        self::assertSame($targetId, $this->assignedPageId($occupied));
        self::assertSame($targetId, $this->assignedPageId($free));
        self::assertSame($previous->getId(), $this->assignedPageId($unrelated));
        $executed = $this->observer->fetchAssociative('SELECT status, previous_id, executed_at FROM monsieurbiz_homepage_switch WHERE id = ?', [$switches[0]['id']]);
        self::assertSame('executed', $executed['status']);
        self::assertSame($previous->getId(), (int) $executed['previous_id']);
        self::assertNotNull($executed['executed_at']);
    }

    public function testSwitchListFiltersPendingAndShowsOnlyRealHistoryInExecutionOrder(): void
    {
        [$channel, $other] = $this->channels();
        $olderTarget = $this->page('First executed page');
        $newerTarget = $this->page('Second executed page');
        $pendingTarget = $this->page('Requested page');
        $otherPendingTarget = $this->page('Other requested page');
        $excludedTarget = $this->page('No change page');
        $now = $this->clock->now();

        // Invert scheduled order so a list sorted by scheduled time cannot pass.
        $old = new HomepageSwitch($channel, $olderTarget, $now->modify('-4 hours'));
        $old->recordExecution(null, $now->modify('-3 hours'));
        $new = new HomepageSwitch($channel, $newerTarget, $now->modify('-5 hours'));
        $new->recordExecution($olderTarget, $now->modify('-1 hour'));
        $elsewhere = new HomepageSwitch($other, $otherPendingTarget, $now->modify('-4 hours'));
        $elsewhere->recordExecution(null, $now->modify('-2 hours'));
        $noChange = new HomepageSwitch($channel, $excludedTarget, $now->modify('-2 hours'));
        $noChange->close('no_change', 'already_active');
        $cancelled = new HomepageSwitch($channel, $pendingTarget, $now->modify('+3 hours'));
        $cancelled->close('cancelled');
        foreach ([
            $old, $new, $elsewhere, $noChange, $cancelled,
            new HomepageSwitch($channel, $pendingTarget, $now->modify('-30 minutes')),
            new HomepageSwitch($other, $pendingTarget, $now->modify('+30 minutes')),
            new HomepageSwitch($channel, $otherPendingTarget, $now->modify('+1 hour')),
        ] as $switch) {
            $this->manager->persist($switch);
        }
        $client = $this->adminBrowser();
        $url = '/admin/homepage-switches/';
        $pendingFilter = ['target' => (string) $pendingTarget->getId(), 'status' => 'pending'];
        $targetNames = static fn (Crawler $page): array => $page->filter('main tbody tr > td:nth-of-type(2)')->each(static fn (Crawler $cell): string => $cell->text());

        $list = $client->request('GET', $url, $pendingFilter);
        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertSame(['Requested page', 'Requested page'], $targetNames($list), 'Both future and overdue requests must be listed while pending.');

        $grid = $client->request('GET', '/admin/homepages/');
        self::assertSame(200, $client->getResponse()->getStatusCode());
        foreach ([[$pendingTarget, '2'], [$excludedTarget, '0']] as [$target, $count]) {
            $counter = $grid->filter('tbody a[href="/admin/homepage-switches/?target=' . $target->getId() . '&status=pending"]');
            self::assertCount(1, $counter);
            self::assertSame($count, trim($counter->getNode(0)->firstChild->textContent));
        }

        $history = $client->request('GET', $url, $pendingFilter + ['history' => '1', 'channel' => $channel->getCode()]);
        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertSame(['Second executed page', 'First executed page'], $targetNames($history));

        $client->request('GET', $url, ['target' => 'bad']);
        self::assertSame(400, $client->getResponse()->getStatusCode());
    }

    public function testMixedCreationRollsBackThePageAssignmentAndMessageWhenTransportFails(): void
    {
        [$occupied, $free] = $this->channels();
        $previous = $this->page('Old homepage', $occupied);
        $client = $this->adminBrowser();
        $client->disableReboot();
        $client->catchExceptions(false);
        $url = '/admin/homepages/new';
        $form = $client->request('GET', $url);
        $session = $client->getSession();
        $session->getFlashBag()->add('success', 'QA pre-existing success');
        $session->save();
        $pageCount = (int) $this->observer->fetchOne('SELECT COUNT(*) FROM monsieurbiz_homepage_homepage');
        $switchCount = (int) $this->observer->fetchOne('SELECT COUNT(*) FROM monsieurbiz_homepage_switch');
        $queueHead = $this->lastMessageId();

        $container = self::getContainer();
        $connection = $this->manager->getConnection();
        $transport = $container->get('messenger.transport.' . self::TRANSPORT);
        $failure = new RuntimeException('QA transport fault after the real queue insert');
        $sender = $this->createMock(SenderInterface::class);
        $sender->expects(self::once())->method('send')->willReturnCallback(
            static function (Envelope $envelope) use ($transport, $connection, $free, $queueHead, $failure): never {
                $transport->send($envelope);
                $message = $envelope->getMessage();
                self::assertInstanceOf(ExecuteHomepageSwitch::class, $message);
                $targetId = $connection->fetchOne('SELECT target_id FROM monsieurbiz_homepage_switch WHERE id = ?', [$message->switchId]);
                self::assertNotFalse($targetId);
                self::assertSame((int) $targetId, (int) $connection->fetchOne('SELECT page_id FROM monsieurbiz_homepage_homepage_channels WHERE channel_id = ?', [$free->getId()]));
                self::assertSame(1, (int) $connection->fetchOne('SELECT COUNT(*) FROM monsieurbiz_homepage_switch_messages WHERE id > ?', [$queueHead]));

                throw $failure;
            },
        );
        $container->set(HomepageSwitchScheduler::class, new HomepageSwitchScheduler($this->manager, $connection, $sender, $this->clock, Homepage::class));
        try {
            $client->request('POST', $url, ['monsieurbiz_homepage_homepage' => [
                '_token' => $form->filter('input[name="monsieurbiz_homepage_homepage[_token]"]')->attr('value'),
                'channels' => [$free->getCode(), $occupied->getCode()],
                'switchNow' => [$occupied->getCode()],
                'enabled' => '1',
                'translations' => array_fill_keys(['en_US', 'fr_FR'], ['name' => 'New homepage', 'content' => '[]']),
            ]]);
            self::fail('The injected failure must abort creation.');
        } catch (RuntimeException $exception) {
            self::assertSame($failure, $exception, $exception->getMessage());
        }

        self::assertSame($pageCount, (int) $this->observer->fetchOne('SELECT COUNT(*) FROM monsieurbiz_homepage_homepage'));
        self::assertSame($switchCount, (int) $this->observer->fetchOne('SELECT COUNT(*) FROM monsieurbiz_homepage_switch'));
        self::assertSame($queueHead, $this->lastMessageId());
        self::assertNull($this->assignedPageId($free));
        self::assertSame($previous->getId(), $this->assignedPageId($occupied));
        self::assertSame(['QA pre-existing success'], $client->getRequest()->getSession()->getFlashBag()->peek('success'));
    }

    public function testAdministrationRoleCannotBypassTheHomepageUpdatePermission(): void
    {
        [$channel] = $this->channels();
        $current = $this->page('Current homepage', $channel);
        $target = $this->page('New homepage');
        $client = $this->adminBrowser();
        $client->disableReboot();
        $denied = false;
        $checker = $this->createMock(AuthorizationCheckerInterface::class);
        $checker->method('isGranted')->willReturnCallback(static function ($configuration, string $permission) use (&$denied): bool {
            return !$denied || 'monsieurbiz_homepage.homepage.update' !== $permission;
        });
        self::getContainer()->set('sylius.resource_controller.authorization_checker', $checker);
        $route = '/admin/homepage-switches/homepage/' . $target->getId() . '/activate-now';
        $form = $client->request('GET', $route);
        self::assertSame(200, $client->getResponse()->getStatusCode());
        $token = $form->filter('input[name="homepage_switch[_token]"]')->attr('value');
        $queueHead = $this->lastMessageId();
        $pageCount = (int) $this->observer->fetchOne('SELECT COUNT(*) FROM monsieurbiz_homepage_homepage');

        $denied = true;
        $client->request('POST', $route, ['homepage_switch' => ['_token' => $token, 'channel' => $channel->getCode()]]);
        self::assertSame(403, $client->getResponse()->getStatusCode());

        $form = $client->request('GET', '/admin/homepages/new');
        self::assertSame(200, $client->getResponse()->getStatusCode());
        $client->request('POST', '/admin/homepages/new', ['monsieurbiz_homepage_homepage' => [
            '_token' => $form->filter('input[name="monsieurbiz_homepage_homepage[_token]"]')->attr('value'),
            'channels' => [$channel->getCode()],
            'switchNow' => [$channel->getCode()],
            'enabled' => '1',
            'translations' => array_fill_keys(['en_US', 'fr_FR'], ['name' => 'New homepage', 'content' => '[]']),
        ]]);
        self::assertSame(403, $client->getResponse()->getStatusCode());
        self::assertSame($pageCount, (int) $this->observer->fetchOne('SELECT COUNT(*) FROM monsieurbiz_homepage_homepage'));
        self::assertSame($queueHead, $this->lastMessageId());
        self::assertSame($current->getId(), $this->assignedPageId($channel));
    }

    public function testFailedInvalidationRetriesWithoutChangingAssignmentOrHistory(): void
    {
        [$channel, $otherChannel] = $this->channels();
        $current = $this->page('Winter', $channel, $otherChannel);
        $target = $this->page('Sale');
        $this->manager->flush();
        $switch = $this->scheduler()->schedule($target, $channel);
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
        $this->scheduler()->execute($message);
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
        $this->scheduler()->execute($message);
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

    private function scheduler(): HomepageSwitchScheduler
    {
        return self::getContainer()->get(HomepageSwitchScheduler::class);
    }

    /** @param array{name: string, content: string} $translation */
    private function assertSubmittedTranslation(Crawler $page, array $translation): void
    {
        foreach ($translation as $field => $value) {
            $input = $page->filter('[name="monsieurbiz_homepage_homepage[translations][en_US][' . $field . ']"]');
            self::assertCount(1, $input);
            self::assertSame($value, $input->attr('value'));
        }
    }

    private function adminBrowser(): KernelBrowser
    {
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

        return $client;
    }
}
