<?php

declare(strict_types=1);

namespace App\Tests\Service\Recommendation;

use App\Service\Recommendation\HistoryMonthSummariser;
use App\Service\Recommendation\ViewerTimeZone;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(HistoryMonthSummariser::class)]
final class HistoryMonthSummariserTest extends TestCase
{
    private HistoryMonthSummariser $summariser;

    protected function setUp(): void
    {
        $this->summariser = new HistoryMonthSummariser();
    }

    public function testCountsAndSumsEachMonthSeparately(): void
    {
        $months = $this->summariser->summarise([
            $this->spendRow('2026-08-16 09:00:00', 1_000),
            $this->spendRow('2026-08-01 09:00:00', 2_000),
            $this->spendRow('2026-07-20 09:00:00', 4_000),
        ], ViewerTimeZone::of('UTC'));

        self::assertCount(2, $months);
        self::assertSame('2026-08', $months[0]->month);
        self::assertSame(2, $months[0]->runCount);
        self::assertSame(3_000, $months[0]->costNanoCredits);
        self::assertSame('2026-07', $months[1]->month);
        self::assertSame(1, $months[1]->runCount);
        self::assertSame(4_000, $months[1]->costNanoCredits);
    }

    public function testOrdersTheMonthsNewestFirstWhateverOrderTheRowsArriveIn(): void
    {
        $months = $this->summariser->summarise([
            $this->spendRow('2026-06-01 09:00:00', 1),
            $this->spendRow('2026-08-01 09:00:00', 1),
            $this->spendRow('2026-07-01 09:00:00', 1),
        ], ViewerTimeZone::of('UTC'));

        self::assertSame(['2026-08', '2026-07', '2026-06'], array_map(
            static fn ($month): string => $month->month,
            $months,
        ));
    }

    /** The stored value is naive UTC; a run at 23:30 UTC on the last day of a
     *  month belongs to the next month for a viewer ahead of UTC, because that
     *  is the date the row prints. */
    public function testBucketsInTheViewersZoneNotInUtc(): void
    {
        $months = $this->summariser->summarise(
            [$this->spendRow('2026-08-31 23:30:00', 500)],
            ViewerTimeZone::of('Europe/Berlin'),
        );

        self::assertSame('2026-09', $months[0]->month);
    }

    public function testAMonthWhoseRunsAllWentUnpricedHasNoTotal(): void
    {
        $months = $this->summariser->summarise([
            $this->spendRow('2026-08-16 09:00:00', null),
            $this->spendRow('2026-08-15 09:00:00', null),
        ], ViewerTimeZone::of('UTC'));

        self::assertSame(2, $months[0]->runCount);
        self::assertNull($months[0]->costNanoCredits);
    }

    public function testAMonthWithOnePricedRunAmongUnpricedOnesSumsOnlyThePriced(): void
    {
        $months = $this->summariser->summarise([
            $this->spendRow('2026-08-16 09:00:00', null),
            $this->spendRow('2026-08-15 09:00:00', 700),
        ], ViewerTimeZone::of('UTC'));

        self::assertSame(2, $months[0]->runCount);
        self::assertSame(700, $months[0]->costNanoCredits);
    }

    public function testACostHandedBackAsAStringIsSummedAsAnInteger(): void
    {
        $months = $this->summariser->summarise([
            [
                'createdAt' => new \DateTimeImmutable('2026-08-16 09:00:00', new \DateTimeZone('UTC')),
                'costNanoCredits' => '900',
            ],
        ], ViewerTimeZone::of('UTC'));

        self::assertSame(900, $months[0]->costNanoCredits);
    }

    public function testAnAccountWithNoRunsHasNoMonths(): void
    {
        self::assertSame([], $this->summariser->summarise([], ViewerTimeZone::of('UTC')));
    }

    /** @return array{createdAt: \DateTimeImmutable, costNanoCredits: int|string|null} */
    private function spendRow(string $createdAtUtc, ?int $costNanoCredits): array
    {
        return [
            'createdAt' => new \DateTimeImmutable($createdAtUtc, new \DateTimeZone('UTC')),
            'costNanoCredits' => $costNanoCredits,
        ];
    }
}
