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

use DateTimeImmutable;
use DateTimeZone;
use Sylius\Component\Channel\Model\ChannelInterface;

class HomepageSwitch
{
    public const PENDING = 'pending';

    public const EXECUTED = 'executed';

    public const NO_CHANGE = 'no_change';

    public const FAILED = 'failed';

    public const SUPERSEDED = 'superseded';

    public const CANCELLED = 'cancelled';

    protected ?int $id = null;

    private string $scheduledAt;

    private string $schedulingKey;

    private string $status = self::PENDING;

    private ?string $reason = null;

    private ?HomepageInterface $previous = null;

    private ?string $executedAt = null;

    public function __construct(
        private ChannelInterface $channel,
        private HomepageInterface $target,
        DateTimeImmutable $scheduledAt
    ) {
        $this->reschedule($scheduledAt);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getChannel(): ChannelInterface
    {
        return $this->channel;
    }

    public function getTarget(): HomepageInterface
    {
        return $this->target;
    }

    public function getPrevious(): ?HomepageInterface
    {
        return $this->previous;
    }

    public function getScheduledAt(): DateTimeImmutable
    {
        return self::date($this->scheduledAt);
    }

    public function getExecutedAt(): ?DateTimeImmutable
    {
        return null === $this->executedAt ? null : self::date($this->executedAt);
    }

    public function getSchedulingKey(): string
    {
        return $this->schedulingKey;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getReason(): ?string
    {
        return $this->reason;
    }

    public function isPending(): bool
    {
        return self::PENDING === $this->status;
    }

    public function reschedule(DateTimeImmutable $scheduledAt): void
    {
        $this->assertPending();
        $this->scheduledAt = (string) self::timestamp($scheduledAt);
        $this->schedulingKey = bin2hex(random_bytes(16));
    }

    public function close(string $status, ?string $reason = null): void
    {
        $this->assertPending();
        $this->status = $status;
        $this->reason = $reason;
    }

    public function recordExecution(?HomepageInterface $previous, DateTimeImmutable $executedAt): void
    {
        $this->close(self::EXECUTED);
        $this->previous = $previous;
        $this->executedAt = (string) self::timestamp($executedAt);
    }

    public static function timestamp(DateTimeImmutable $date): int
    {
        return $date->getTimestamp() * 1_000_000 + (int) $date->format('u');
    }

    private static function date(string $timestamp): DateTimeImmutable
    {
        $seconds = intdiv((int) $timestamp, 1_000_000);
        $microseconds = (int) $timestamp % 1_000_000;

        return (new DateTimeImmutable('@' . $seconds))
            ->modify(sprintf('%+d microseconds', $microseconds))
            ->setTimezone(new DateTimeZone('UTC'))
        ;
    }

    private function assertPending(): void
    {
        if (!$this->isPending()) {
            throw new \LogicException('Only pending homepage switches can be changed.');
        }
    }
}
