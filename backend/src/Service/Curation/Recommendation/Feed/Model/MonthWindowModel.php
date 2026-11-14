<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Feed\Model;

use App\Service\Clock\Model\ViewerTimeZoneModel;
use App\Service\Recommendation\Exception\UnknownHistoryMonthException;

/**
 * One calendar month as a half-open UTC range (`>= startUtc AND < endUtc`): cut in the viewer's zone, then expressed in
 * the column's naive UTC. The other order files a viewer's late-evening runs under the following month.
 */
final readonly class MonthWindowModel
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
    public static function of(string $month, ViewerTimeZoneModel $viewer): self
    {
        if (1 !== preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) {
            throw new UnknownHistoryMonthException(sprintf('"%s" is not a calendar month.', $month));
        }

        // `+1 month` from local midnight on the first keeps local midnight across a DST change; adding days would not.
        $start = new \DateTimeImmutable($month . '-01 00:00:00', $viewer->zone);

        return new self($month, self::inUtc($start), self::inUtc($start->modify('+1 month')));
    }

    private static function inUtc(\DateTimeImmutable $local): \DateTimeImmutable
    {
        return $local->setTimezone(new \DateTimeZone('UTC'));
    }
}
