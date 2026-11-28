<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\Entry;
use App\Entity\EntryState;
use App\Entity\Feed;
use App\Entity\Subscription;
use App\Entity\User;
use App\Enum\FeedLanguage;
use App\Repository\ReadingHistoryRepository;
use App\Repository\SavedSearchRepository;
use App\Repository\YourStreamSourceRepository;
use App\Service\Curation\YourStream\Factory\EntryStemmingFactory;
use App\Service\Curation\YourStream\InterestArticles;
use App\Service\Curation\YourStream\SearchTermStems;
use App\Service\Curation\YourStream\YourStreamBackground;
use App\Service\Curation\YourStream\YourStreamExplainer;
use App\Service\Curation\YourStream\YourStreamScorer;
use App\Service\Curation\YourStream\YourStreamWordBuilder\YourStreamWordBuilder;
use App\Service\Curation\YourStream\YourStreamWordSelection;
use App\Service\Foundation\Clock\NaiveUtcClock;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\MockClock;

final class YourStreamExplainFixtures
{
    public const string NOW = '2026-11-28 10:00:00';
    public const string FAVOURITED_AT = '2026-09-01 10:00:00';
    public const string BACKGROUND_AT = '2026-11-24 10:00:00';
    public const string TODAY_AT = '2026-11-28 08:00:00';

    private int $nextEntry = 0;

    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    public function explainer(SavedSearchRepository $savedSearches): YourStreamExplainer
    {
        $sources = new YourStreamSourceRepository($this->entityManager);
        $clock = new NaiveUtcClock(new MockClock(self::NOW));
        $background = new YourStreamBackground(
            $sources,
            new EntryStemmingFactory(StemmingWiring::equivalents(), StemmingWiring::stopWordStems()),
            $clock,
        );
        $builder = new YourStreamWordBuilder(
            $sources,
            new SearchTermStems(
                StemmingWiring::equivalents(),
                StemmingWiring::stopWordStems(),
                LanguageWiring::stopWords(),
            ),
            $background,
            new YourStreamWordSelection(),
        );

        return new YourStreamExplainer(
            new InterestArticles(new ReadingHistoryRepository($this->entityManager), $savedSearches),
            $builder,
            $background,
            $sources,
            new YourStreamScorer(),
            $clock,
        );
    }

    public function reader(string $email): User
    {
        $user = new User($email, new \DateTimeImmutable('2026-07-01T00:00:00Z'));
        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return $user;
    }

    public function subscribedFeed(User $user, string $url, FeedLanguage $language): Feed
    {
        $feed = new Feed($url);
        $feed->recordTraits($feed->getTraits()->withLanguage($language));
        $this->entityManager->persist($feed);
        $this->entityManager->persist(new Subscription($user, $feed, new \DateTimeImmutable('2026-07-01')));
        $this->entityManager->flush();

        return $feed;
    }

    public function favourite(User $user, Feed $feed, string $title): void
    {
        $state = new EntryState($user, $this->entry($feed, $title, null, self::FAVOURITED_AT));
        StateMarks::markFavorite($state);
        $this->entityManager->persist($state);
        $this->entityManager->flush();
    }

    public function entries(Feed $feed, int $count, string $title, string $effectiveAt): void
    {
        for ($index = 0; $index < $count; $index++) {
            $this->entry($feed, $title, null, $effectiveAt);
        }
        $this->entityManager->flush();
    }

    public function entryWithSummary(Feed $feed, string $title, string $summary, string $effectiveAt): void
    {
        $this->entry($feed, $title, $summary, $effectiveAt);
        $this->entityManager->flush();
    }

    private function entry(Feed $feed, string $title, ?string $summary, string $effectiveAt): Entry
    {
        $at = new \DateTimeImmutable($effectiveAt);
        $entry = new Entry($feed, 'explain-' . $this->nextEntry++, null, $title, $at, $at);
        $entry->recordText($entry->getText()->withSummary($summary));
        $this->entityManager->persist($entry);

        return $entry;
    }
}
