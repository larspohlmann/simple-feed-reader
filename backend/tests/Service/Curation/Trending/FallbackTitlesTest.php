<?php

declare(strict_types=1);

namespace App\Tests\Service\Curation\Trending;

use App\Entity\Entry;
use App\Enum\FeedLanguage;
use App\Enum\TrendingWindow;
use App\Service\Curation\Trending\Model\StoredStoryModel;
use App\Service\Curation\Trending\Model\StoryMatchModel;
use App\Service\Curation\Trending\Model\TrendingStoryModel;
use App\Service\Curation\Trending\Pass\TrendingRun;
use App\Service\Curation\Trending\StoryMatching;
use App\Tests\DbTestCase;
use App\Tests\Support\TrendingFixtures;
use App\Tests\Support\TrendingWiring;
use Symfony\Component\Clock\MockClock;

final class FallbackTitlesTest extends DbTestCase
{
    private const string TICKER_ONLY = '+++';

    private TrendingFixtures $fixtures;

    private MockClock $clock;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixtures = new TrendingFixtures($this->entityManager);
        $this->clock = new MockClock('2031-05-14 12:00:00', 'UTC');
    }

    public function testAHeadlineOfOnlyTickerMarksIsNoCandidate(): void
    {
        $entries = $this->portStrike();

        $titles = $this->fallbackTitlesOfTheStory([$entries[0]]);

        self::assertSame(['Streik legt Hamburg lahm'], $titles);
    }

    public function testAStoryWhoseHeadlinesAreAllTickerMarksTakesNoFallback(): void
    {
        self::assertSame([], $this->fallbackTitlesOfTheStory($this->portStrike()));
    }

    public function testAnUnchangedTitledStoryKeepsItsFallbackWhateverTheMemberOrder(): void
    {
        $entries = $this->portStrike();
        $ids = array_map(static fn (Entry $entry): int => $entry->requireId(), $entries);

        $titles = $this->fallbackTitlesOfTheStory([], [new StoredStoryModel(1, array_reverse($ids), $ids[1], true)]);

        self::assertSame([], $titles);
    }

    public function testAStoryWithoutARecordedFallbackEntryIsTitledAgain(): void
    {
        $ids = array_map(static fn (Entry $entry): int => $entry->requireId(), $this->portStrike());

        $titles = $this->fallbackTitlesOfTheStory([], [new StoredStoryModel(1, $ids, null, true)]);

        self::assertCount(1, $titles);
    }

    public function testAStoryWhoseFallbackEntryLeftIsTitledAgain(): void
    {
        $ids = array_map(static fn (Entry $entry): int => $entry->requireId(), $this->portStrike());

        $titles = $this->fallbackTitlesOfTheStory([], [new StoredStoryModel(1, $ids, 99_999_999, true)]);

        self::assertCount(1, $titles);
    }

    public function testAStoryThatNeverHadATitleIsTitledAgain(): void
    {
        $ids = array_map(static fn (Entry $entry): int => $entry->requireId(), $this->portStrike());

        $titles = $this->fallbackTitlesOfTheStory([], [new StoredStoryModel(1, $ids, $ids[1], false)]);

        self::assertCount(1, $titles);
    }

    /**
     * @param list<Entry>             $tickered the entries whose title turns to ticker marks once the story is grouped
     * @param list<StoredStoryModel>  $stored
     *
     * @return list<string> the fallback titles the story's assignments received
     */
    private function fallbackTitlesOfTheStory(array $tickered, array $stored = []): array
    {
        $grouping = TrendingWiring::grouping($this->entityManager, $this->clock);
        $run = $grouping->collect($this->fixtures->owner()->requireId(), TrendingWindow::Week);
        $stories = $grouping->stories($run);
        self::assertCount(1, $stories);
        foreach ($tickered as $entry) {
            $this->retitle($entry, self::TICKER_ONLY);
        }

        $match = $this->titled($run, $stories, $stored);

        return array_values(array_filter(
            array_map(static fn ($assignment): ?string => $assignment->fallbackTitle?->title, $match->assignments),
            static fn (?string $title): bool => $title !== null,
        ));
    }

    /**
     * @param list<TrendingStoryModel> $stories
     * @param list<StoredStoryModel>   $stored
     */
    private function titled(TrendingRun $run, array $stories, array $stored): StoryMatchModel
    {
        return TrendingWiring::fallbackTitles($this->entityManager)->titledAll(
            $run,
            (new StoryMatching())->reshape($stories, $stored),
            $stored,
        );
    }

    /** @return list<Entry> */
    private function portStrike(): array
    {
        $entries = [];
        $titles = [
            'Hafenstreik legt Hamburg lahm',
            'Hafenstreik in Hamburg: Terminals lahm',
            'Streik legt Hamburg lahm',
        ];
        foreach ($titles as $index => $title) {
            $feed = $this->fixtures->feed('outlet' . $index);
            $feed->recordTraits($feed->getTraits()->withLanguage(FeedLanguage::German));
            $entries[] = $this->fixtures->article(
                $feed,
                $title,
                \sprintf('2031-05-14 %02d:00:00', 8 + $index),
                $feed->getUrl() . '/' . md5($title),
            );
        }
        $this->fixtures->flush();

        return $entries;
    }

    private function retitle(Entry $entry, string $title): void
    {
        $this->entityManager->getConnection()->executeStatement(
            'UPDATE entry SET title = ? WHERE id = ?',
            [$title, $entry->requireId()],
        );
    }
}
