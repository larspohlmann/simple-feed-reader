<?php

declare(strict_types=1);

namespace App\Tests\Service\Curation\Trending\Support;

use App\Enum\TrendingWindow;
use App\Service\Curation\Trending\Pass\TrendingRun;
use App\Service\Curation\Trending\Support\GroupProfiles;
use App\Tests\Support\TrendingRuns;
use PHPUnit\Framework\TestCase;

final class GroupProfilesTest extends TestCase
{
    public function testAStemMoreThanAThirdOfMembersCarryIsKept(): void
    {
        $run = $this->runOf([
            'Zorbel Plimth ankert',
            'Zorbel Plimth ruft',
            'Frindle Gashop wartet',
        ]);

        $profile = GroupProfiles::build($run, [1, 2, 3]);

        $stemId = $run->headlines->collectHeadlineStemIds(1)[0];
        $expectedWeight = 2 / 3 * $run->headlines->computeRarity($stemId);
        self::assertEqualsWithDelta($expectedWeight, $profile->stemWeights[$stemId], 1e-9);
    }

    public function testAStemOnlyAThirdCarryIsLeftOut(): void
    {
        $run = $this->runOf([
            'Quaffle Brumpt landet',
            'Zorbel Plimth ruft',
            'Frindle Gashop wartet',
        ]);

        $profile = GroupProfiles::build($run, [1, 2, 3]);

        $stemIds = $run->headlines->collectHeadlineStemIds(1);
        self::assertNotEmpty($stemIds);
        foreach ($stemIds as $stemId) {
            self::assertArrayNotHasKey($stemId, $profile->stemWeights);
        }
    }

    public function testTheSpanRunsFromTheOldestToTheNewestMember(): void
    {
        $run = TrendingRuns::of(TrendingWindow::Day);
        $times = ['2031-05-07 08:00:00', '2031-05-07 11:00:00', '2031-05-07 09:00:00'];
        foreach ($times as $index => $time) {
            $run->add(TrendingRuns::entry($index + 1, $index + 1, 'Zorbel Plimth ankert', $time), [], null);
        }

        $profile = GroupProfiles::build($run, [1, 2, 3]);

        self::assertSame($this->readTimestamp('2031-05-07 08:00:00'), $profile->firstSeen);
        self::assertSame($this->readTimestamp('2031-05-07 11:00:00'), $profile->lastSeen);
    }

    private function readTimestamp(string $moment): int
    {
        return new \DateTimeImmutable($moment, new \DateTimeZone('UTC'))->getTimestamp();
    }

    /**
     * @param list<string> $headlines
     */
    private function runOf(array $headlines): TrendingRun
    {
        $run = TrendingRuns::of(TrendingWindow::Day);
        foreach ($headlines as $index => $headline) {
            $run->add(TrendingRuns::entry($index + 1, $index + 1, $headline, '2031-05-07 08:00:00'), [], null);
        }

        return $run;
    }
}
