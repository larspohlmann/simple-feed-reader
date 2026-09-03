<?php

declare(strict_types=1);

namespace App\Service\Recommendation;

use App\Service\Recommendation\Exception\UnknownHistoryMonthException;

/**
 * One calendar month of a viewer's history, as a range the database can be
 * asked about (#409). Half-open — `>= startUtc AND < endUtc` — so a run at a
 * month's last instant cannot fall into both windows, and no end-of-month
 * arithmetic has to know February's length. Boundaries come out in UTC because
 * that is the wall clock Doctrine persists: the month is cut in the viewer's
 * zone, then expressed in the column's zone. The other way round buckets a
 * viewer's late-evening runs into the following month.
 */
final readonly class MonthWindow
{
    private function __construct(
        public string $month,
        public \DateTimeImmutable $startUtc,
        public \DateTimeImmutable $endUtc,
    ) {
    }

    /**
     * @throws UnknownHistoryMonthException
     */
    public static function of(string $month, ViewerTimeZone $viewer): self
    {
        if (1 !== preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) {
            throw new UnknownHistoryMonthException(sprintf('"%s" is not a calendar month.', $month));
        }

        // Anchored to local midnight on the first, then advanced by a whole
        // month rather than by a day count: `+1 month` keeps local midnight
        // across a daylight-saving change, where adding 30 or 31 days would
        // land an hour out.
        $start = new \DateTimeImmutable($month . '-01 00:00:00', $viewer->zone);

        return new self($month, self::inUtc($start), self::inUtc($start->modify('+1 month')));
    }

    private static function inUtc(\DateTimeImmutable $local): \DateTimeImmutable
    {
        return $local->setTimezone(new \DateTimeZone('UTC'));
    }
}
