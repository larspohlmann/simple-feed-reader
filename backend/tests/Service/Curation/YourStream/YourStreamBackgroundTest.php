<?php

declare(strict_types=1);

namespace App\Tests\Service\Curation\YourStream;

use App\Entity\Entry;
use App\Entity\EntryText;
use App\Entity\Feed;
use App\Entity\InclusionChange;
use App\Entity\InterestTerm;
use App\Entity\InterestTermSet;
use App\Entity\Subscription;
use App\Entity\User;
use App\Enum\FeedLanguage;
use App\Repository\InterestTermRepository;
use App\Repository\YourStreamSourceRepository;
use App\Service\Curation\YourStream\Factory\EntryStemmingFactory;
use App\Service\Curation\YourStream\YourStreamBackground;
use App\Service\Foundation\Clock\NaiveUtcClock;
use App\Service\Foundation\Stemming\Support\WordStems;
use App\Tests\DbTestCase;
use App\Tests\Support\StemmingWiring;
use Symfony\Component\Clock\MockClock;

final class YourStreamBackgroundTest extends DbTestCase
{
    private const string NOW = '2026-11-27 10:00:00';
    private const int BATCH = 500;

    private User $user;
    private Feed $feed;
    private InterestTermSet $set;
    private YourStreamBackground $rarity;
    private int $nextEntry = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = new User('rarity@example.com', new \DateTimeImmutable('2026-07-01T00:00:00Z'));
        $this->entityManager->persist($this->user);
        $this->feed = $this->subscribedFeed('https://example.com/rarity.xml');
        $this->set = new InterestTermSet(
            $this->user,
            new \DateTimeImmutable('2026-11-01 00:00:00'),
            new \DateTimeImmutable('2026-11-01 00:00:00'),
            0,
        );
        $this->entityManager->persist($this->set);
        $this->entityManager->flush();

        $storedTerms = self::getContainer()->get(InterestTermRepository::class);
        self::assertInstanceOf(InterestTermRepository::class, $storedTerms);
        $this->rarity = new YourStreamBackground(
            new YourStreamSourceRepository($this->entityManager),
            $storedTerms,
            new EntryStemmingFactory(StemmingWiring::equivalents(), StemmingWiring::stopWordStems()),
            $this->entityManager,
            new NaiveUtcClock(new MockClock(self::NOW)),
        );
    }

    public function testARareTermWeighsMoreThanACommonOne(): void
    {
        $this->term('solar', FeedLanguage::English);
        $this->term('energy', FeedLanguage::English);
        $this->entries(600, FeedLanguage::English, [3 => 'solar', 300 => 'energy']);

        $this->refreshRarities();

        self::assertEqualsWithDelta(log(602 / 4), $this->termRarity('solar', FeedLanguage::English), 1e-9);
        self::assertEqualsWithDelta(log(602 / 301), $this->termRarity('energy', FeedLanguage::English), 1e-9);
    }

    public function testASmallWindowCountsAtTheFloor(): void
    {
        $this->term('solar', FeedLanguage::English);
        $this->entries(10, FeedLanguage::English, [2 => 'solar']);

        $this->refreshRarities();

        self::assertEqualsWithDelta(log(502 / 3), $this->termRarity('solar', FeedLanguage::English), 1e-9);
    }

    public function testCutFormTermsCountOnlyEntriesWithoutALanguage(): void
    {
        $this->term('solar', null);
        $this->entries(2, FeedLanguage::English, [2 => 'solar']);
        $this->entries(3, null, [1 => 'solar']);

        $this->refreshRarities();

        self::assertEqualsWithDelta(log(502 / 2), $this->termRarity('solar', null), 1e-9);
    }

    public function testEntriesOutsideTheWindowOrOutsideForYouDoNotCount(): void
    {
        $this->term('solar', FeedLanguage::English);
        $this->entries(1, FeedLanguage::English, [1 => 'solar'], '2026-10-01 00:00:00');
        $excluded = $this->subscribedFeed('https://example.com/excluded.xml');
        $this->entries(1, FeedLanguage::English, [1 => 'solar'], null, $excluded);
        $this->entries(1, FeedLanguage::English, [1 => 'solar']);
        $this->excludeFromForYou($excluded);

        $this->refreshRarities();

        self::assertEqualsWithDelta(log(502 / 2), $this->termRarity('solar', FeedLanguage::English), 1e-9);
    }

    public function testTheRefreshIsStampedOnTheSet(): void
    {
        $this->term('solar', FeedLanguage::English);

        $this->refreshRarities();

        self::assertEquals(new \DateTimeImmutable(self::NOW), $this->set->getRaritiesRefreshedAt());
    }

    public function testAWindowLargerThanTheSampleCountsOnlyItsNewestEntries(): void
    {
        $this->term('solar', FeedLanguage::English);
        $this->entriesInBatches(YourStreamBackground::SAMPLE_ENTRIES + 1, [1 => 'solar']);

        $this->refreshRarities();

        $sampled = YourStreamBackground::SAMPLE_ENTRIES;
        self::assertEqualsWithDelta(log(($sampled + 2) / 1), $this->termRarity('solar', FeedLanguage::English), 1e-9);
    }

    public function testASetWithoutTermsIsStampedAndWeighsNothing(): void
    {
        $this->entries(3, FeedLanguage::English, [1 => 'solar']);

        $this->refreshRarities();

        self::assertEquals(new \DateTimeImmutable(self::NOW), $this->set->getRaritiesRefreshedAt());
    }

    private function refreshRarities(): void
    {
        $this->rarity->refreshRarities($this->set, $this->rarity->buildSweepPass());
    }

    private function subscribedFeed(string $url): Feed
    {
        $feed = new Feed($url);
        $this->entityManager->persist($feed);
        $this->entityManager->persist(new Subscription($this->user, $feed, new \DateTimeImmutable('2026-07-01')));
        $this->entityManager->flush();

        return $feed;
    }

    private function excludeFromForYou(Feed $feed): void
    {
        $subscription = $this->entityManager->getRepository(Subscription::class)
            ->findOneBy(['user' => $this->user, 'feed' => $feed]);
        self::assertInstanceOf(Subscription::class, $subscription);
        $subscription->changeInclusions(new InclusionChange(forYou: false));
        $this->entityManager->flush();
    }

    private function term(string $word, ?FeedLanguage $language): void
    {
        $stem = $language === null
            ? WordStems::cut($word)
            : StemmingWiring::stemmer()->stem($word, $language);
        $this->entityManager->persist(new InterestTerm($this->user, $stem, $language, 1.0));
        $this->entityManager->flush();
    }

    /**
     * @param array<int, string> $wordFor how many of the entries (from the first) carry each word, as {count => word}
     */
    private function entries(
        int $count,
        ?FeedLanguage $language,
        array $wordFor,
        ?string $effectiveAt = null,
        ?Feed $feed = null,
    ): void {
        for ($index = 0; $index < $count; $index++) {
            $title = 'filler';
            foreach ($wordFor as $carriers => $word) {
                $title .= $index < $carriers ? ' ' . $word : '';
            }
            $date = new \DateTimeImmutable($effectiveAt ?? '2026-11-20 00:00:00');
            $entry = new Entry($feed ?? $this->feed, 'guid-' . $this->nextEntry++, null, $title, $date, $date);
            $entry->recordText(new EntryText(null, null, $language));
            $this->entityManager->persist($entry);
        }
        $this->entityManager->flush();
    }

    /**
     * English entries as entries() writes them, flushed and cleared every BATCH so the unit of work never holds the
     * whole window; the fixtures are found again afterwards.
     *
     * @param array<int, string> $wordFor
     */
    private function entriesInBatches(int $count, array $wordFor): void
    {
        $feedId = $this->feed->requireId();
        for ($written = 0; $written < $count; $written += self::BATCH) {
            $feed = $this->entityManager->getReference(Feed::class, $feedId);
            $carriers = array_map(static fn (int $carrier): int => $carrier - $written, array_keys($wordFor));
            $this->entries(
                min(self::BATCH, $count - $written),
                FeedLanguage::English,
                array_combine($carriers, array_values($wordFor)),
                null,
                $feed,
            );
            $this->entityManager->clear();
        }
        $this->user = $this->requireFound(User::class, $this->user->requireId());
        $this->feed = $this->requireFound(Feed::class, $feedId);
        $this->set = $this->requireFound(InterestTermSet::class, $this->user->requireId());
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    private function requireFound(string $class, int $id): object
    {
        $found = $this->entityManager->find($class, $id);
        self::assertInstanceOf($class, $found);

        return $found;
    }

    private function termRarity(string $word, ?FeedLanguage $language): float
    {
        $stem = $language === null
            ? WordStems::cut($word)
            : StemmingWiring::stemmer()->stem($word, $language);
        foreach ($this->entityManager->getRepository(InterestTerm::class)->findBy(['user' => $this->user]) as $term) {
            if ($term->getStem() === $stem && $term->getLanguage() === $language) {
                return $term->getRarity();
            }
        }
        self::fail('No such term: ' . $word);
    }
}
