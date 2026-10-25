<?php

declare(strict_types=1);

namespace App\Service\SubscriptionHistory\Model;

/** One subscription's (or one account's) new and read articles on one day. */
final readonly class DayCountModel
{
    public function __construct(public int $arrived, public int $read)
    {
    }

    public static function none(): self
    {
        return new self(0, 0);
    }

    public function plus(self $other): self
    {
        return new self($this->arrived + $other->arrived, $this->read + $other->read);
    }
}
