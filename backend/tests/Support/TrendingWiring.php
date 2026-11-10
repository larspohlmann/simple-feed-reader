<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\TrendingSnapshot;
use App\Repository\TrendingEntryRepository;
use App\Repository\TrendingSnapshotRepository;
use App\Service\Clock\NaiveUtcClock;
use App\Service\Trending\HeadlineFiller;
use App\Service\Trending\SnapshotBuilder\TrendingSnapshotBuilder;
use App\Service\Trending\StoryGrouping;
use App\Service\Trending\StoryLinks;
use App\Service\Trending\StoryLinkSource\HeadlineLinks;
use App\Service\Trending\StoryLinkSource\SharedUrlLinks;
use App\Service\Trending\TrendingGrouping;
use App\Service\Trending\TrendingRebuild;
use App\Service\Url\UrlNormalizer;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\ServiceLocator;

/** Trending services built by hand: the test container hands out none of the ones used once. */
final class TrendingWiring
{
    public static function rebuild(EntityManagerInterface $entityManager, ClockInterface $clock): TrendingRebuild
    {
        $naiveClock = new NaiveUtcClock($clock);
        $snapshots = self::snapshots($entityManager);
        $builder = new TrendingSnapshotBuilder(
            self::grouping($entityManager, $clock),
            $snapshots,
            $entityManager,
            $naiveClock,
        );

        return new TrendingRebuild($builder, $snapshots, $naiveClock);
    }

    public static function grouping(EntityManagerInterface $entityManager, ClockInterface $clock): TrendingGrouping
    {
        return new TrendingGrouping(
            new TrendingEntryRepository($entityManager),
            new UrlNormalizer(),
            new HeadlineFiller(),
            self::storyLinks(),
            new StoryGrouping(),
            new NaiveUtcClock($clock),
        );
    }

    public static function storyLinks(): StoryLinks
    {
        return new StoryLinks(new ServiceLocator([
            TaggedItemOf::index(HeadlineLinks::class) => static fn (): HeadlineLinks => new HeadlineLinks(),
            TaggedItemOf::index(SharedUrlLinks::class) => static fn (): SharedUrlLinks => new SharedUrlLinks(),
        ]));
    }

    public static function snapshots(EntityManagerInterface $entityManager): TrendingSnapshotRepository
    {
        return $entityManager->getRepository(TrendingSnapshot::class);
    }

    private function __construct()
    {
    }
}
