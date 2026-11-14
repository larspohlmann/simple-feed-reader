<?php

declare(strict_types=1);

namespace App\Service\SubscriptionHistory\Model;

/** One day of an account's series; no counts when any subscription's day can no longer be known. */
final readonly class ActivityDayModel
{
    private function __construct(public string $date, private ?DayCountModel $counts)
    {
    }

    public static function known(string $date, DayCountModel $counts): self
    {
        return new self($date, $counts);
    }

    public static function unknown(string $date): self
    {
        return new self($date, null);
    }

    public function arrived(): ?int
    {
        return $this->counts?->arrived;
    }

    public function read(): ?int
    {
        return $this->counts?->read;
    }
}
