<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\TrendingSnapshot;
use App\Entity\TrendingStoryFollow;
use App\Entity\User;
use App\Repository\TrendingEntryRepository;
use App\Repository\TrendingSnapshotRepository;
use App\Repository\TrendingStoryFollowRepository;
use App\Repository\TrendingStoryHistoryRepository;
use App\Service\Foundation\Clock\NaiveUtcClock;
use App\Service\Curation\Trending\CardLanguage;
use App\Service\Curation\Trending\Factory\SnowballStemmerFactory;
use App\Service\Curation\Trending\GermanCompoundSplitter;
use App\Service\Curation\Trending\GermanLexicon\BundledGermanLexicon;
use App\Service\Curation\Trending\HeadlineEquivalents;
use App\Service\Curation\Trending\HeadlineFiller;
use App\Service\Curation\Trending\HeadlineStemmer;
use App\Service\Curation\Trending\SnapshotBuilder\TrendingSnapshotBuilder;
use App\Service\Curation\Trending\StoryGrouping;
use App\Service\Curation\Trending\StoryLinks;
use App\Service\Curation\Trending\StoryBuildPolicy\DayStoryBuildPolicy;
use App\Service\Curation\Trending\StoryBuildPolicy\WeekStoryBuildPolicy;
use App\Service\Curation\Trending\StoryMatching;
use App\Service\Curation\Trending\StoryLinkSource\HeadlineLinks;
use App\Service\Curation\Trending\StoryLinkSource\SharedUrlLinks;
use App\Service\Curation\Trending\TrendingGrouping;
use App\Service\Curation\Trending\TrendingRebuild;
use App\Service\Ingestion\Language\Factory\LanguageDetectionFactory;
use App\Service\Ingestion\Language\LanguageGuess;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Clock\ClockInterface;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Psr\Container\ContainerInterface;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

/** Trending services built by hand: the test container hands out none of the ones used once. */
final class TrendingWiring
{
    public static function rebuild(
        EntityManagerInterface $entityManager,
        ManagerRegistry $registry,
        ClockInterface $clock,
        LockFactory $locks = new LockFactory(new InMemoryStore()),
    ): TrendingRebuild {
        $naiveClock = new NaiveUtcClock($clock);
        $snapshots = self::snapshots($entityManager);
        $builder = new TrendingSnapshotBuilder(
            self::grouping($entityManager, $clock),
            new TrendingEntryRepository($entityManager),
            self::policies($entityManager),
            new TrendingStoryHistoryRepository($entityManager),
            $snapshots,
            $entityManager,
            $naiveClock,
            $locks,
        );

        return new TrendingRebuild(
            $builder,
            $snapshots,
            new TrendingEntryRepository($entityManager),
            $entityManager->getRepository(User::class),
            $naiveClock,
            new NullLogger(),
            $registry,
        );
    }

    public static function policies(EntityManagerInterface $entityManager): ContainerInterface
    {
        $matching = new StoryMatching();

        return new ServiceLocator([
            'day' => static fn (): DayStoryBuildPolicy => new DayStoryBuildPolicy($matching),
            'week' => static fn (): WeekStoryBuildPolicy => new WeekStoryBuildPolicy(
                $matching,
                new TrendingStoryHistoryRepository($entityManager),
            ),
        ]);
    }

    public static function grouping(EntityManagerInterface $entityManager, ClockInterface $clock): TrendingGrouping
    {
        return new TrendingGrouping(
            new TrendingEntryRepository($entityManager),
            self::filler(),
            self::equivalents(),
            self::storyLinks(),
            new StoryGrouping(),
            new NaiveUtcClock($clock),
            self::cardLanguage(),
        );
    }

    public static function cardLanguage(): CardLanguage
    {
        return new CardLanguage(new LanguageGuess((new LanguageDetectionFactory())->createForFeedLanguages()));
    }

    public static function stemmer(): HeadlineStemmer
    {
        return new HeadlineStemmer(new SnowballStemmerFactory());
    }

    public static function filler(): HeadlineFiller
    {
        return new HeadlineFiller(self::stemmer(), LanguageWiring::stopWords());
    }

    public static function equivalents(): HeadlineEquivalents
    {
        return new HeadlineEquivalents(self::stemmer(), new GermanCompoundSplitter(self::germanLexicon()));
    }

    public static function germanLexicon(): BundledGermanLexicon
    {
        return new BundledGermanLexicon(dirname(__DIR__) . '/Fixtures/german-lexicon');
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

    public static function follows(EntityManagerInterface $entityManager): TrendingStoryFollowRepository
    {
        return $entityManager->getRepository(TrendingStoryFollow::class);
    }

    private function __construct()
    {
    }
}
