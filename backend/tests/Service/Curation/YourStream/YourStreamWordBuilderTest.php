<?php

declare(strict_types=1);

namespace App\Tests\Service\Curation\YourStream;

use App\Entity\Entry;
use App\Entity\EntryState;
use App\Entity\Feed;
use App\Entity\Subscription;
use App\Entity\User;
use App\Enum\FeedLanguage;
use App\Repository\YourStreamSourceRepository;
use App\Service\Curation\YourStream\Factory\EntryStemmingFactory;
use App\Service\Curation\YourStream\Model\InterestInputsModel;
use App\Service\Curation\YourStream\Model\StreamEntryModel;
use App\Service\Curation\YourStream\Model\StreamWordModel;
use App\Service\Curation\YourStream\SearchTermStems;
use App\Service\Curation\YourStream\YourStreamBackground;
use App\Service\Curation\YourStream\YourStreamWordBuilder\YourStreamWordBuilder;
use App\Service\Curation\YourStream\YourStreamWordSelection;
use App\Service\Foundation\Clock\NaiveUtcClock;
use App\Tests\DbTestCase;
use App\Tests\Support\StateMarks;
use App\Tests\Support\StemmingWiring;
use Symfony\Component\Clock\MockClock;

final class YourStreamWordBuilderTest extends DbTestCase
{
    private const string NOW = '2026-11-27 10:00:00';

    private User $user;
    private YourStreamBackground $background;
    private YourStreamWordBuilder $builder;
    private int $nextEntry = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = new User('builder@example.com', new \DateTimeImmutable('2026-07-01T00:00:00Z'));
        $this->entityManager->persist($this->user);
        $this->entityManager->flush();
        $sources = new YourStreamSourceRepository($this->entityManager);
        $this->background = new YourStreamBackground(
            $sources,
            new EntryStemmingFactory(StemmingWiring::equivalents(), StemmingWiring::stopWordStems()),
            new NaiveUtcClock(new MockClock(self::NOW)),
        );
        $this->builder = new YourStreamWordBuilder(
            $sources,
            new SearchTermStems(StemmingWiring::equivalents(), StemmingWiring::stopWordStems()),
            $this->background,
            new YourStreamWordSelection(),
        );
    }

    public function testTheBuilderStemsArticlesInTheirFeedLanguage(): void
    {
        $feed = $this->subscribedFeed('https://de.example.com/feed.xml', FeedLanguage::German);
        $favourites = [
            $this->favourite($feed, 'Bundesliga heute'),
            $this->favourite($feed, 'Bundesliga morgen'),
        ];
        $this->entries($feed, 600, 'Wetterbericht');

        $words = $this->buildWords($favourites, []);

        self::assertEquals([new StreamWordModel(
            FeedLanguage::German,
            StemmingWiring::stemmer()->stem('bundesliga', FeedLanguage::German),
            'Bundesliga',
            1.0,
            log(602 / 1),
        )], $words);
    }

    public function testASavedSearchIsStemmedInEveryStreamLanguageAndTheCutForm(): void
    {
        $this->subscribedFeed('https://de.example.com/feed.xml', FeedLanguage::German);
        $this->subscribedFeed('https://en.example.com/feed.xml', FeedLanguage::English);

        $words = $this->buildWords([], ['Wärmepumpe']);

        self::assertSame(['Wärmepumpe'], array_values(array_unique(array_column($this->rows($words), 1))));
        self::assertSame(['de', 'en', ''], array_values(array_unique(array_column($this->rows($words), 0))));
    }

    public function testNoInterestsBuildNoWords(): void
    {
        $feed = $this->subscribedFeed('https://de.example.com/feed.xml', FeedLanguage::German);
        $this->entries($feed, 3, 'Bundesliga');

        self::assertSame([], $this->buildWords([], []));
    }

    /**
     * @param list<StreamEntryModel> $articles
     * @param list<string>           $searchTerms
     *
     * @return list<StreamWordModel>
     */
    private function buildWords(array $articles, array $searchTerms): array
    {
        return $this->builder->buildWords(
            new InterestInputsModel($this->user->requireId(), $articles, $searchTerms),
            $this->background->buildSweepPass(),
        );
    }

    /**
     * @param list<StreamWordModel> $words
     *
     * @return list<array{string, string}>
     */
    private function rows(array $words): array
    {
        return array_map(
            static fn (StreamWordModel $word): array => [$word->language->value ?? '', $word->word],
            $words,
        );
    }

    private function subscribedFeed(string $url, FeedLanguage $language): Feed
    {
        $feed = new Feed($url);
        $feed->recordTraits($feed->getTraits()->withLanguage($language));
        $this->entityManager->persist($feed);
        $this->entityManager->persist(new Subscription($this->user, $feed, new \DateTimeImmutable('2026-07-01')));
        $this->entityManager->flush();

        return $feed;
    }

    private function favourite(Feed $feed, string $title): StreamEntryModel
    {
        $entry = $this->entry($feed, $title, '2026-09-01 10:00:00');
        $state = new EntryState($this->user, $entry);
        StateMarks::markFavorite($state);
        $this->entityManager->persist($state);
        $this->entityManager->flush();

        return StreamEntryModel::fromEntry($entry);
    }

    private function entries(Feed $feed, int $count, string $title): void
    {
        for ($index = 0; $index < $count; $index++) {
            $this->entry($feed, $title, '2026-11-20 10:00:00');
        }
        $this->entityManager->flush();
    }

    private function entry(Feed $feed, string $title, string $effectiveAt): Entry
    {
        $at = new \DateTimeImmutable($effectiveAt);
        $entry = new Entry($feed, 'builder-' . $this->nextEntry++, null, $title, $at, $at);
        $this->entityManager->persist($entry);

        return $entry;
    }
}
