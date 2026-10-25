<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\SubscriptionDayRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/** One subscription's counts for one completed UTC day; written once, and outlives the entries it counted. */
#[ORM\Entity(repositoryClass: SubscriptionDayRepository::class)]
#[ORM\Table(name: 'subscription_day')]
#[ORM\UniqueConstraint(name: 'uniq_subscription_day', columns: ['subscription_id', 'day'])]
final class SubscriptionDay
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Subscription::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Subscription $subscription;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $day;

    #[ORM\Column]
    private int $arrived;

    #[ORM\Column]
    private int $readCount;

    public function __construct(Subscription $subscription, \DateTimeImmutable $day, int $arrived, int $readCount)
    {
        $this->subscription = $subscription;
        $this->day = $day;
        $this->arrived = $arrived;
        $this->readCount = $readCount;
    }

    public function getSubscription(): Subscription
    {
        return $this->subscription;
    }

    public function getDay(): \DateTimeImmutable
    {
        return $this->day;
    }

    public function getArrived(): int
    {
        return $this->arrived;
    }

    public function getReadCount(): int
    {
        return $this->readCount;
    }
}
