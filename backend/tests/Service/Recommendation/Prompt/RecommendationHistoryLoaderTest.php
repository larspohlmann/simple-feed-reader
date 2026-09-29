<?php

declare(strict_types=1);

namespace App\Tests\Service\Recommendation\Prompt;

use App\Entity\Entry;
use App\Entity\EntryState;
use App\Entity\Feed;
use App\Entity\RecommendationHistoryCaps;
use App\Entity\RecommendationPoolLimits;
use App\Entity\RecommendationSettings;
use App\Entity\Subscription;
use App\Entity\User;
use App\Enum\RecommendationBatchSize;
use App\Service\Recommendation\Prompt\RecommendationHistoryLoader;
use App\Service\Recommendation\Settings\Model\EffectiveRecommendationSettingsModel;
use App\Service\Recommendation\Settings\Model\RecommendationPackingSettingsModel;
use App\Tests\DbTestCase;

final class RecommendationHistoryLoaderTest extends DbTestCase
{
    private User $user;
    private Feed $feed;
    private Subscription $subscription;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = new User('history@example.com', new \DateTimeImmutable('2026-07-01T00:00:00Z'));
        $this->entityManager->persist($this->user);

        $this->feed = new Feed('https://example.com/feed.xml');
        $this->feed->setTitle('Example');
        $this->entityManager->persist($this->feed);

        $this->subscription = new Subscription(
            $this->user,
            $this->feed,
            new \DateTimeImmutable('2026-07-01T00:00:00Z'),
        );
        $this->entityManager->persist($this->subscription);

        $this->entityManager->flush();
    }

    public function testEachEntryAppearsOnlyInItsHighestSection(): void
    {
        $entryA = $this->entry('A', '2026-07-10T00:00:00Z');
        $entryB = $this->entry('B', '2026-07-11T00:00:00Z');
        $entryC = $this->entry('C', '2026-07-12T00:00:00Z');
        $this->entry('D', '2026-07-13T00:00:00Z');
        $entryE = $this->entry('E', '2026-07-14T00:00:00Z');

        $stateA = new EntryState($this->user, $entryA);
        $stateA->markFavorite();
        $stateA->markKept();
        $stateA->markViewed(new \DateTimeImmutable('2026-07-10T09:00:00Z'));
        $this->entityManager->persist($stateA);

        $stateB = new EntryState($this->user, $entryB);
        $stateB->markKept();
        $stateB->markViewed(new \DateTimeImmutable('2026-07-11T09:00:00Z'));
        $this->entityManager->persist($stateB);

        $stateC = new EntryState($this->user, $entryC);
        $stateC->markViewed(new \DateTimeImmutable('2026-07-12T09:00:00Z'));
        $this->entityManager->persist($stateC);

        $stateE = new EntryState($this->user, $entryE);
        $stateE->markFavorite();
        $this->entityManager->persist($stateE);

        $this->entityManager->flush();

        $history = $this->loader()->load($this->userId(), $this->settings());

        self::assertSame(['E', 'A'], array_map(static fn ($line) => $line->title, $history->favorites));
        self::assertSame(['B'], array_map(static fn ($line) => $line->title, $history->kept));
        self::assertSame(['C'], array_map(static fn ($line) => $line->title, $history->viewed));
        self::assertSame($entryE->requireId(), $history->favorites[0]->entryId);
    }

    public function testCapsApplyNewestFirst(): void
    {
        $entryC = $this->entry('C', '2026-07-10T00:00:00Z');
        $entryB = $this->entry('B', '2026-07-11T00:00:00Z');
        $entryF = $this->entry('F', '2026-07-12T00:00:00Z');

        $stateC = new EntryState($this->user, $entryC);
        $stateC->markViewed(new \DateTimeImmutable('2026-07-15T10:00:00Z'));
        $this->entityManager->persist($stateC);

        $stateB = new EntryState($this->user, $entryB);
        $stateB->markKept();
        $stateB->markViewed(new \DateTimeImmutable('2026-07-15T11:00:00Z'));
        $this->entityManager->persist($stateB);

        $stateF = new EntryState($this->user, $entryF);
        $stateF->markViewed(new \DateTimeImmutable('2026-07-15T12:00:00Z'));
        $this->entityManager->persist($stateF);

        $this->entityManager->flush();

        $history = $this->loader()->load($this->userId(), $this->settings(viewedCap: 1));

        self::assertSame(['F'], array_map(static fn ($line) => $line->title, $history->viewed));
    }

    public function testEachHistoryListReadsItsOwnCap(): void
    {
        foreach (['A', 'B', 'C'] as $index => $guid) {
            $favorite = new EntryState($this->user, $this->entry('F' . $guid, '2026-07-1' . $index . 'T00:00:00Z'));
            $favorite->markFavorite();
            $kept = new EntryState($this->user, $this->entry('K' . $guid, '2026-07-1' . $index . 'T00:00:00Z'));
            $kept->markKept();
            $viewed = new EntryState($this->user, $this->entry('V' . $guid, '2026-07-1' . $index . 'T00:00:00Z'));
            $viewed->markViewed(new \DateTimeImmutable('2026-07-15T1' . $index . ':00:00Z'));
            $this->entityManager->persist($favorite);
            $this->entityManager->persist($kept);
            $this->entityManager->persist($viewed);
        }
        $this->entityManager->flush();

        $history = $this->loader()->load($this->userId(), $this->settings(favoritesCap: 1, keptCap: 2, viewedCap: 3));

        self::assertCount(1, $history->favorites);
        self::assertCount(2, $history->kept);
        self::assertCount(3, $history->viewed);
    }

    public function testViewedOrdersByViewedAtNotEffectiveDate(): void
    {
        // F published before C but viewed after it → F first.
        $entryF = $this->entry('F', '2026-07-01T00:00:00Z');
        $entryC = $this->entry('C', '2026-07-20T00:00:00Z');

        $stateF = new EntryState($this->user, $entryF);
        $stateF->markViewed(new \DateTimeImmutable('2026-07-25T09:00:00Z'));
        $this->entityManager->persist($stateF);

        $stateC = new EntryState($this->user, $entryC);
        $stateC->markViewed(new \DateTimeImmutable('2026-07-25T08:00:00Z'));
        $this->entityManager->persist($stateC);

        $this->entityManager->flush();

        $history = $this->loader()->load($this->userId(), $this->settings());

        self::assertSame(['F', 'C'], array_map(static fn ($line) => $line->title, $history->viewed));
    }

    public function testFeedNamePrefersTheSubscriptionsCustomTitle(): void
    {
        $this->subscription->setCustomTitle('My Custom Feed');
        $entry = $this->entry('A', '2026-07-10T00:00:00Z');
        $state = new EntryState($this->user, $entry);
        $state->markFavorite();
        $this->entityManager->persist($state);
        $this->entityManager->flush();

        $history = $this->loader()->load($this->userId(), $this->settings());

        self::assertSame('My Custom Feed', $history->favorites[0]->feedName);
    }

    public function testDescriptionPrefersTheSummaryOverTheContentHtml(): void
    {
        $entry = $this->entry('A', '2026-07-10T00:00:00Z');
        $entry->setSummary('Summary text');
        $entry->setContentHtml('<p>Content text</p>');
        $state = new EntryState($this->user, $entry);
        $state->markFavorite();
        $this->entityManager->persist($state);
        $this->entityManager->flush();

        $history = $this->loader()->load($this->userId(), $this->settings());

        self::assertSame('Summary text', $history->favorites[0]->description);
    }

    public function testFeedNameFallsBackToTheFeedTitleWithoutACustomTitle(): void
    {
        $entry = $this->entry('A', '2026-07-10T00:00:00Z');
        $state = new EntryState($this->user, $entry);
        $state->markFavorite();
        $this->entityManager->persist($state);
        $this->entityManager->flush();

        $history = $this->loader()->load($this->userId(), $this->settings());

        self::assertSame('Example', $history->favorites[0]->feedName);
    }

    public function testFeedNameFallsBackToTheUrlWhenTheFeedTitleIsEmpty(): void
    {
        $this->feed->setTitle('');
        $entry = $this->entry('A', '2026-07-10T00:00:00Z');
        $state = new EntryState($this->user, $entry);
        $state->markFavorite();
        $this->entityManager->persist($state);
        $this->entityManager->flush();

        $history = $this->loader()->load($this->userId(), $this->settings());

        self::assertSame($this->feed->getUrl(), $history->favorites[0]->feedName);
    }

    private function entry(string $guid, string $published): Entry
    {
        $publishedAt = new \DateTimeImmutable($published);
        $entry = new Entry(
            $this->feed,
            $guid,
            'https://example.com/' . $guid,
            $guid,
            new \DateTimeImmutable('2026-07-01T00:00:00Z'),
            $publishedAt,
        );
        $entry->setPublishedAt($publishedAt);
        $this->entityManager->persist($entry);
        $this->entityManager->flush();

        return $entry;
    }

    private function settings(
        int $favoritesCap = 40,
        int $keptCap = 40,
        int $viewedCap = 80,
    ): EffectiveRecommendationSettingsModel {
        return new EffectiveRecommendationSettingsModel(
            guidancePrompt: null,
            historyCaps: new RecommendationHistoryCaps($favoritesCap, $keptCap, $viewedCap),
            poolLimits: new RecommendationPoolLimits(500, RecommendationSettings::DEFAULT_LOOKBACK_DAYS, 50),
            packing: new RecommendationPackingSettingsModel(
                contextWindow: 32768,
                contextWindowSource: 'fallback',
                batchSize: RecommendationBatchSize::Medium,
                maximumBatchSize: RecommendationPackingSettingsModel::DEFAULT_MAXIMUM_BATCH_SIZE,
            ),
            debugEnabled: false,
        );
    }

    private function userId(): int
    {
        return $this->user->requireId();
    }

    private function loader(): RecommendationHistoryLoader
    {
        /** @var RecommendationHistoryLoader $loader */
        $loader = self::getContainer()->get(RecommendationHistoryLoader::class);

        return $loader;
    }
}
