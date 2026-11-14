<?php

declare(strict_types=1);

namespace App\Service\SubscriptionHistory\Model;

use App\Service\SubscriptionHistory\Support\UtcDay;

final readonly class DayWindowModel
{
    private const int DAYS = 30;
    private const int AVERAGE_DAYS = 7;

    /**
     * @param list<string> $dates oldest first, today last
     */
    private function __construct(public array $dates)
    {
    }

    public static function lastDays(string $today): self
    {
        $dates = [];
        for ($offset = self::DAYS - 1; $offset >= 0; --$offset) {
            $dates[] = UtcDay::shift($today, -$offset);
        }

        return new self($dates);
    }

    public function oldest(): string
    {
        return $this->dates[0];
    }

    public function today(): string
    {
        return $this->dates[\count($this->dates) - 1];
    }

    /** @return list<string> */
    public function completedDates(): array
    {
        return \array_slice($this->dates, 0, -1);
    }

    /**
     * Today is partial, so an average covers the seven days before it.
     *
     * @template T
     *
     * @param list<T> $series one item per day, oldest first, today last
     *
     * @return list<T>
     */
    public static function averagedWeekOf(array $series): array
    {
        return \array_slice($series, -(self::AVERAGE_DAYS + 1), self::AVERAGE_DAYS);
    }
}
