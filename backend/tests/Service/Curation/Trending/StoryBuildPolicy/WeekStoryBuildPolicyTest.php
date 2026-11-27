<?php

declare(strict_types=1);

namespace App\Tests\Service\Curation\Trending\StoryBuildPolicy;

use App\Enum\TrendingWindow;
use App\Repository\TrendingStoryHistoryRepository;
use App\Service\Curation\Trending\Model\StoredStoryModel;
use App\Service\Curation\Trending\Model\StoryBuildModel;
use App\Service\Curation\Trending\Model\StoryMemberModel;
use App\Service\Curation\Trending\Model\TrendingStoryModel;
use App\Service\Curation\Trending\Pass\HeadlineWindow;
use App\Service\Curation\Trending\Pass\TrendingRun;
use App\Service\Curation\Trending\StoryBuildPolicy\WeekStoryBuildPolicy;
use App\Service\Curation\Trending\StoryMatching;
use App\Tests\DbTestCase;
use App\Tests\Support\StemmingWiring;
use App\Tests\Support\TrendingFixtures;
use App\Tests\Support\TrendingWiring;

final class WeekStoryBuildPolicyTest extends DbTestCase
{
    public function testReshapesAndPrunesOutsideTheWeek(): void
    {
        $policy = new WeekStoryBuildPolicy(
            new StoryMatching(),
            new TrendingStoryHistoryRepository($this->entityManager),
            TrendingWiring::fallbackTitles($this->entityManager),
        );
        $group = new TrendingStoryModel(
            [new StoryMemberModel(1, 101, null), new StoryMemberModel(2, 101, null)],
            new \DateTimeImmutable('2031-05-14 08:00:00', new \DateTimeZone('UTC')),
            new \DateTimeImmutable('2031-05-14 09:00:00', new \DateTimeZone('UTC')),
        );
        $match = $policy->match(
            self::emptyRun(),
            [$group],
            [new StoredStoryModel(7, [1, 2]), new StoredStoryModel(9, [1, 2])],
        );

        $fixtures = new TrendingFixtures($this->entityManager);
        $old = $fixtures->entry($fixtures->feed('old'), 'Old', '2031-05-01 08:00:00');
        $fixtures->story(TrendingWindow::Week, '2031-05-01 08:00:00', $old);
        $builtAt = new \DateTimeImmutable('2031-05-14 12:00:00', new \DateTimeZone('UTC'));
        $pruned = $policy->prune(new StoryBuildModel(
            $fixtures->owner()->requireId(),
            TrendingWindow::Week,
            $builtAt->modify('-7 days'),
            $builtAt,
        ));

        self::assertCount(1, $match->merges);
        self::assertSame(1, $pruned);
        $remaining = $this->entityManager->getConnection()->fetchFirstColumn('SELECT id FROM trending_story');
        self::assertCount(0, $remaining);
    }

    private static function emptyRun(): TrendingRun
    {
        return new TrendingRun(
            TrendingWindow::Week,
            new \DateTimeImmutable('2031-05-13 12:00:00', new \DateTimeZone('UTC')),
            new HeadlineWindow(TrendingWiring::filler(), StemmingWiring::equivalents()),
        );
    }
}
