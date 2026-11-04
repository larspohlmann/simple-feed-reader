<?php

declare(strict_types=1);

namespace App\Tests\Service\Trending\StoryLinkSource;

use App\Enum\TrendingWindow;
use App\Service\Trending\Model\HeadlineOverlapModel;
use App\Service\Trending\Model\NearMissModel;
use App\Service\Trending\Pass\TrendingRun;
use App\Service\Trending\StoryLinkSource\HeadlineLinks;
use App\Tests\Support\TrendingRuns;
use PHPUnit\Framework\TestCase;

final class HeadlineLinksTest extends TestCase
{
    public function testAWeightOfFourteenAtFortyPercentOfTheLighterEntryLinks(): void
    {
        self::assertTrue((new HeadlineLinks())->admits(new HeadlineOverlapModel(1, 2, 14.0, 35.0)));
    }

    public function testAWeightJustUnderFourteenDoesNotLink(): void
    {
        self::assertFalse((new HeadlineLinks())->admits(new HeadlineOverlapModel(1, 2, 13.99, 20.0)));
    }

    public function testAShareJustUnderFortyPercentDoesNotLink(): void
    {
        self::assertFalse((new HeadlineLinks())->admits(new HeadlineOverlapModel(1, 2, 14.0, 35.01)));
    }

    public function testThreeSharedRareHeadlineStemsLinkTwoFeeds(): void
    {
        $run = self::dayPair(10, 20, '2031-05-14 09:00:00');

        $links = (new HeadlineLinks())->links($run);

        self::assertCount(1, $links);
        self::assertSame('1:2', $links[0]->pairKey());
        self::assertEqualsWithDelta(3 * log(1502 / 3), $links[0]->strength, 0.0001);
    }

    public function testTwoSharedStemsWeighTooLittle(): void
    {
        $run = TrendingRuns::of(TrendingWindow::Day);
        $run->add(TrendingRuns::entry(1, 10, 'Vulkan Island', '2031-05-14 08:00:00'), []);
        $run->add(TrendingRuns::entry(2, 20, 'Island Vulkan', '2031-05-14 09:00:00'), []);

        self::assertSame([], (new HeadlineLinks())->links($run));
    }

    public function testAHeavyLighterEntryFailsTheShareRule(): void
    {
        $run = TrendingRuns::of(TrendingWindow::Day);
        $run->add(
            TrendingRuns::entry(
                1,
                10,
                'Vulkan Ausbruch Island Reykjavik Flughafen Asche Lava Notstand',
                '2031-05-14 08:00:00',
            ),
            [],
        );
        $run->add(
            TrendingRuns::entry(
                2,
                20,
                'Vulkan Ausbruch Island Reise Hotel Strand Sonne Meer Urlaub',
                '2031-05-14 09:00:00',
            ),
            [],
        );

        self::assertSame([], (new HeadlineLinks())->links($run));
    }

    public function testOneFeedNeverLinksToItself(): void
    {
        self::assertSame([], (new HeadlineLinks())->links(self::dayPair(10, 10, '2031-05-14 09:00:00')));
    }

    public function testTheDayWindowLinksAcrossThirtySixHoursAndNoFurther(): void
    {
        $links = new HeadlineLinks();

        self::assertCount(1, $links->links(self::dayPair(10, 20, '2031-05-14 12:00:00')));
        self::assertSame([], $links->links(self::dayPair(10, 20, '2031-05-14 12:00:01')));
    }

    public function testTheWeekWindowLinksAcrossFortyEightHoursAndNoFurther(): void
    {
        $links = new HeadlineLinks();

        self::assertCount(1, $links->links(self::weekPair('2031-05-14 00:00:00')));
        self::assertSame([], $links->links(self::weekPair('2031-05-14 00:00:01')));
    }

    public function testAPairFailingOnlyTheWeightIsANearMissWithItsNumbers(): void
    {
        $run = TrendingRuns::of(TrendingWindow::Day);
        $run->add(TrendingRuns::entry(1, 10, 'Vulkan Island', '2031-05-14 08:00:00'), []);
        $run->add(TrendingRuns::entry(2, 20, 'Island Vulkan', '2031-05-14 09:00:00'), []);

        self::assertSame(['shared weight 12.4 < 14'], self::rulesOf((new HeadlineLinks())->nearMisses($run)));
    }

    public function testAPairFailingOnlyTheGapIsANearMiss(): void
    {
        $run = self::weekPair('2031-05-14 04:00:00');

        self::assertSame(['gap 52.0 h > 48 h'], self::rulesOf((new HeadlineLinks())->nearMisses($run)));
    }

    public function testAPairFailingOnlyTheShareIsANearMiss(): void
    {
        $run = TrendingRuns::of(TrendingWindow::Day);
        $run->add(
            TrendingRuns::entry(
                1,
                10,
                'Vulkan Ausbruch Island Reykjavik Flughafen Asche Lava Notstand',
                '2031-05-14 08:00:00',
            ),
            [],
        );
        $run->add(
            TrendingRuns::entry(
                2,
                20,
                'Vulkan Ausbruch Island Reise Hotel Strand Sonne Meer Urlaub',
                '2031-05-14 09:00:00',
            ),
            [],
        );

        self::assertSame(
            ['share of the lighter entry 0.36 < 0.4'],
            self::rulesOf((new HeadlineLinks())->nearMisses($run)),
        );
    }

    public function testAPairFailingTwoRulesOrNoneIsNoNearMiss(): void
    {
        $links = new HeadlineLinks();
        $twoRules = TrendingRuns::of(TrendingWindow::Day);
        $twoRules->add(TrendingRuns::entry(1, 10, 'Vulkan Island', '2031-05-12 00:00:00'), []);
        $twoRules->add(TrendingRuns::entry(2, 20, 'Island Vulkan', '2031-05-14 00:00:00'), []);

        self::assertSame([], $links->nearMisses($twoRules));
        self::assertSame([], $links->nearMisses(self::dayPair(10, 20, '2031-05-14 08:00:00')));
    }

    public function testAPairOfOneFeedFailingOnlyTheWeightIsNoNearMiss(): void
    {
        $run = TrendingRuns::of(TrendingWindow::Day);
        $run->add(TrendingRuns::entry(1, 10, 'Vulkan Island', '2031-05-14 08:00:00'), []);
        $run->add(TrendingRuns::entry(2, 10, 'Island Vulkan', '2031-05-14 09:00:00'), []);

        self::assertSame([], (new HeadlineLinks())->nearMisses($run));
    }

    /**
     * @param list<NearMissModel> $nearMisses
     *
     * @return list<string>
     */
    private static function rulesOf(array $nearMisses): array
    {
        return array_map(static fn (NearMissModel $nearMiss): string => $nearMiss->failedRule, $nearMisses);
    }

    private static function dayPair(int $firstFeedId, int $secondFeedId, string $secondDate): TrendingRun
    {
        $run = TrendingRuns::of(TrendingWindow::Day);
        $run->add(TrendingRuns::entry(1, $firstFeedId, TrendingRuns::VOLCANO_HEADLINE, '2031-05-13 00:00:00'), []);
        $run->add(TrendingRuns::entry(2, $secondFeedId, TrendingRuns::VOLCANO_REPLY_HEADLINE, $secondDate), []);

        return $run;
    }

    private static function weekPair(string $secondDate): TrendingRun
    {
        $run = TrendingRuns::of(TrendingWindow::Week);
        $run->add(TrendingRuns::entry(1, 10, TrendingRuns::VOLCANO_HEADLINE, '2031-05-12 00:00:00'), []);
        $run->add(TrendingRuns::entry(2, 20, TrendingRuns::VOLCANO_REPLY_HEADLINE, $secondDate), []);

        return $run;
    }
}
