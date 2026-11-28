<?php

declare(strict_types=1);

namespace App\Tests\Service\Curation\YourStream;

use App\Entity\Entry;
use App\Entity\EntryText;
use App\Entity\Feed;
use App\Entity\InclusionChange;
use App\Entity\Subscription;
use App\Entity\User;
use App\Enum\FeedLanguage;
use App\Repository\YourStreamSourceRepository;
use App\Service\Curation\YourStream\Factory\EntryStemmingFactory;
use App\Service\Curation\YourStream\Pass\RarityTally;
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
    private YourStreamBackground $background;
    private int $nextEntry = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = new User('rarity@example.com', new \DateTimeImmutable('2026-07-01T00:00:00Z'));
        $this->entityManager->persist($this->user);
        $this->feed = $this->subscribedFeed('https://example.com/rarity.xml');
        $this->entityManager->flush();
        $this->background = new YourStreamBackground(
            new YourStreamSourceRepository($this->entityManager),
            new EntryStemmingFactory(StemmingWiring::equivalents(), StemmingWiring::stopWordStems()),
            new NaiveUtcClock(new MockClock(self::NOW)),
        );
    }

    public function testTheBackgroundCountsEntriesAndTheWantedStemsPerLanguage(): void
    {
        $this->entries(600, FeedLanguage::English, [3 => 'solar', 300 => 'energy']);

        $tally = $this->collectBackground(['solar', 'energy'], FeedLanguage::English);

        self::assertSame(600, $tally->countEntries('en'));
        self::assertSame(3, $tally->countDocuments('en', $this->stem('solar', FeedLanguage::English)));
        self::assertSame(300, $tally->countDocuments('en', $this->stem('energy', FeedLanguage::English)));
        self::assertSame(0, $tally->countDocuments('en', $this->stem('filler', FeedLanguage::English)));
    }

    public function testCutFormStemsCountOnlyEntriesWithoutALanguage(): void
    {
        $this->entries(2, FeedLanguage::English, [2 => 'solar']);
        $this->entries(3, null, [1 => 'solar']);

        $tally = $this->collectBackground(['solar'], null);

        self::assertSame(3, $tally->countEntries(''));
        self::assertSame(1, $tally->countDocuments('', WordStems::cut('solar')));
    }

    public function testEntriesOutsideTheWindowOrOutsideYourStreamDoNotCount(): void
    {
        $this->entries(1, FeedLanguage::English, [1 => 'solar'], '2026-10-01 00:00:00');
        $excluded = $this->subscribedFeed('https://example.com/excluded.xml');
        $this->entries(1, FeedLanguage::English, [1 => 'solar'], null, $excluded);
        $this->entries(1, FeedLanguage::English, [1 => 'solar']);
        $this->excludeFromYourStream($excluded);

        $tally = $this->collectBackground(['solar'], FeedLanguage::English);

        self::assertSame(1, $tally->countEntries('en'));
        self::assertSame(1, $tally->countDocuments('en', $this->stem('solar', FeedLanguage::English)));
    }

    public function testAWindowLargerThanTheSampleCountsOnlyItsNewestEntries(): void
    {
        $this->entriesInBatches(YourStreamBackground::SAMPLE_ENTRIES + 1, [1 => 'solar']);

        $tally = $this->collectBackground(['solar'], FeedLanguage::English);

        self::assertSame(YourStreamBackground::SAMPLE_ENTRIES, $tally->countEntries('en'));
        self::assertSame(0, $tally->countDocuments('en', $this->stem('solar', FeedLanguage::English)));
    }

    /** @param list<string> $words */
    private function collectBackground(array $words, ?FeedLanguage $language): RarityTally
    {
        $wanted = [];
        foreach ($words as $word) {
            $wanted[$language->value ?? ''][$this->stem($word, $language)] = true;
        }

        return $this->background->collectBackground(
            $this->user->requireId(),
            $this->background->buildSweepPass(),
            $wanted,
        );
    }

    private function stem(string $word, ?FeedLanguage $language): string
    {
        return $language === null ? WordStems::cut($word) : StemmingWiring::stemmer()->stem($word, $language);
    }

    private function subscribedFeed(string $url): Feed
    {
        $feed = new Feed($url);
        $this->entityManager->persist($feed);
        $this->entityManager->persist(new Subscription($this->user, $feed, new \DateTimeImmutable('2026-07-01')));
        $this->entityManager->flush();

        return $feed;
    }

    private function excludeFromYourStream(Feed $feed): void
    {
        $subscription = $this->entityManager->getRepository(Subscription::class)
            ->findOneBy(['user' => $this->user, 'feed' => $feed]);
        self::assertInstanceOf(Subscription::class, $subscription);
        $subscription->changeInclusions(new InclusionChange(yourStream: false));
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
}
