<?php

declare(strict_types=1);

namespace App\Service\SubscriptionHistory\Model;

final readonly class SubscriptionActivityModel
{
    private const int AVERAGE_DAYS = 7;

    /**
     * @param list<ActivityDayModel> $days oldest first, today last
     */
    public function __construct(public array $days)
    {
    }

    /** Today is partial, so the week is the seven days before it. */
    public function arrivedDailyAverage(): ?float
    {
        $known = array_values(array_filter(
            array_map(
                static fn (ActivityDayModel $day): ?int => $day->arrived(),
                \array_slice($this->days, -(self::AVERAGE_DAYS + 1), self::AVERAGE_DAYS),
            ),
            static fn (?int $arrived): bool => $arrived !== null,
        ));

        return $known === [] ? null : array_sum($known) / \count($known);
    }

    public function readTotal(): int
    {
        return array_sum(array_map(static fn (ActivityDayModel $day): int => $day->read() ?? 0, $this->days));
    }
}
