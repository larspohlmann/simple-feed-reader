<?php

declare(strict_types=1);

namespace App\Tests\Service\Curation\Trending\StoryLinkSource;

use App\Enum\TrendingWindow;
use App\Service\Curation\Trending\Model\StoryLinkModel;
use App\Service\Curation\Trending\StoryLinkSource\SharedUrlLinks;
use App\Tests\Support\TrendingRuns;
use PHPUnit\Framework\TestCase;

final class SharedUrlLinksTest extends TestCase
{
    public function testEntriesOfDifferentFeedsSharingAUrlHashLinkAboveEveryHeadlineLink(): void
    {
        $run = TrendingRuns::of(TrendingWindow::Day);
        $run->add(TrendingRuns::entry(1, 10, 'Original article', '2031-05-14 08:00:00'), ['hash-a']);
        $run->add(TrendingRuns::entry(2, 20, 'Reposted', '2031-05-14 09:00:00'), ['hash-b', 'hash-a']);
        $run->add(TrendingRuns::entry(3, 30, 'Unrelated', '2031-05-14 09:30:00'), ['hash-c']);

        $links = (new SharedUrlLinks())->links($run);

        self::assertCount(1, $links);
        self::assertSame('1:2', $links[0]->pairKey());
        self::assertSame(StoryLinkModel::sameUrl(1, 2)->strength, $links[0]->strength);
    }

    public function testEveryPairOfAUrlsEntriesLinksOnce(): void
    {
        $run = TrendingRuns::of(TrendingWindow::Day);
        $run->add(TrendingRuns::entry(1, 10, 'One', '2031-05-14 08:00:00'), ['hash-a', 'hash-b']);
        $run->add(TrendingRuns::entry(2, 20, 'Two', '2031-05-14 08:10:00'), ['hash-a', 'hash-b']);
        $run->add(TrendingRuns::entry(3, 30, 'Three', '2031-05-14 08:20:00'), ['hash-a']);

        $keys = array_map(
            static fn (StoryLinkModel $link): string => $link->pairKey(),
            (new SharedUrlLinks())->links($run),
        );
        sort($keys);

        self::assertSame(['1:2', '1:3', '2:3'], $keys);
    }

    public function testOneFeedNeverLinksToItself(): void
    {
        $run = TrendingRuns::of(TrendingWindow::Day);
        $run->add(TrendingRuns::entry(1, 10, 'One', '2031-05-14 08:00:00'), ['hash-a']);
        $run->add(TrendingRuns::entry(2, 10, 'Two', '2031-05-14 08:10:00'), ['hash-a']);

        self::assertSame([], (new SharedUrlLinks())->links($run));
    }

    public function testEntriesFurtherApartThanTheGapDoNotLink(): void
    {
        $run = TrendingRuns::of(TrendingWindow::Day);
        $run->add(TrendingRuns::entry(1, 10, 'One', '2031-05-13 00:00:00'), ['hash-a']);
        $run->add(TrendingRuns::entry(2, 20, 'Two', '2031-05-14 12:00:01'), ['hash-a']);

        self::assertSame([], (new SharedUrlLinks())->links($run));
    }
}
