<?php

declare(strict_types=1);

namespace App\Tests\Service\Curation\Recommendation\Pool;

use App\Entity\Entry;
use App\Entity\EntryShare;
use App\Entity\EntryState;
use App\Entity\Feed;
use App\Entity\LinkCardContent;
use App\Entity\RecommendationHistoryCaps;
use App\Entity\RecommendationPoolLimits;
use App\Entity\RecommendationSettings;
use App\Entity\Subscription;
use App\Entity\User;
use App\Enum\CanonicalItemKind;
use App\Enum\RecommendationBatchSize;
use App\Repository\LinkCardRepository;
use App\Service\Curation\Recommendation\Pool\RecommendationHistoryLoader;
use App\Service\Curation\Recommendation\Settings\Model\EffectiveRecommendationSettingsModel;
use App\Service\Curation\Recommendation\Settings\Model\RecommendationPackingSettingsModel;
use App\Tests\DbTestCase;
use App\Tests\Support\LikedPostFixtures;
use App\Tests\Support\SharedLinks;

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

    public function testASharedEntryAppearsInSharedNewestSharedFirst(): void
    {
        $older = $this->sharedState('Older', '2026-07-10T00:00:00Z', '2026-07-12T00:00:00Z');
        $newer = $this->sharedState('Newer', '2026-07-09T00:00:00Z', '2026-07-13T00:00:00Z');
        $this->entityManager->persist($older);
        $this->entityManager->persist($newer);
        $this->entityManager->flush();

        $history = $this->loader()->load($this->userId(), $this->settings());

        self::assertSame(['Newer', 'Older'], array_map(static fn ($line) => $line->title, $history->shared));
    }

    public function testAFavoriteThatIsAlsoSharedAppearsInFavoritesOnly(): void
    {
        $state = $this->sharedState('Both', '2026-07-10T00:00:00Z', '2026-07-12T00:00:00Z');
        $state->markFavorite();
        $this->entityManager->persist($state);
        $this->entityManager->flush();

        $history = $this->loader()->load($this->userId(), $this->settings());

        self::assertSame(['Both'], array_map(static fn ($line) => $line->title, $history->favorites));
        self::assertSame([], $history->shared);
    }

    public function testASharedAndKeptEntryAppearsInSharedNotKept(): void
    {
        $state = $this->sharedState('Both', '2026-07-10T00:00:00Z', '2026-07-12T00:00:00Z');
        $state->markKept();
        $this->entityManager->persist($state);
        $this->entityManager->flush();

        $history = $this->loader()->load($this->userId(), $this->settings());

        self::assertSame(['Both'], array_map(static fn ($line) => $line->title, $history->shared));
        self::assertSame([], $history->kept);
    }

    public function testASharedAndViewedEntryAppearsInSharedNotViewed(): void
    {
        $state = $this->sharedState('Both', '2026-07-10T00:00:00Z', '2026-07-12T00:00:00Z');
        $state->markViewed(new \DateTimeImmutable('2026-07-12T01:00:00Z'));
        $this->entityManager->persist($state);
        $this->entityManager->flush();

        $history = $this->loader()->load($this->userId(), $this->settings());

        self::assertSame(['Both'], array_map(static fn ($line) => $line->title, $history->shared));
        self::assertSame([], $history->viewed);
    }

    public function testTheSharedSectionReadsItsOwnCap(): void
    {
        foreach (['A', 'B', 'C'] as $index => $guid) {
            $this->entityManager->persist(
                $this->sharedState($guid, '2026-07-10T00:00:00Z', '2026-07-1' . $index . 'T00:00:00Z'),
            );
        }
        $this->entityManager->flush();

        $history = $this->loader()->load($this->userId(), $this->settings(sharedCap: 2));

        self::assertSame(['C', 'B'], array_map(static fn ($line) => $line->title, $history->shared));
    }

    public function testALikedPostAppearsInLikedNewestLikedFirst(): void
    {
        $this->likeEntry('Older', '2026-07-10T00:00:00Z', '2026-07-12T00:00:00Z');
        $this->likeEntry('Newer', '2026-07-09T00:00:00Z', '2026-07-13T00:00:00Z');
        $this->entityManager->flush();

        $history = $this->loader()->load($this->userId(), $this->settings());

        self::assertSame(['Newer', 'Older'], array_map(static fn ($line) => $line->title, $history->liked));
    }

    public function testALikedAndKeptEntryAppearsInLikedNotKept(): void
    {
        $this->likeEntry('Both', '2026-07-10T00:00:00Z', '2026-07-12T00:00:00Z')->markKept();
        $this->entityManager->flush();

        $history = $this->loader()->load($this->userId(), $this->settings());

        self::assertSame(['Both'], array_map(static fn ($line) => $line->title, $history->liked));
        self::assertSame([], $history->kept);
    }

    public function testTheLikedSectionReadsItsOwnCap(): void
    {
        foreach (['A', 'B', 'C'] as $index => $guid) {
            $this->likeEntry($guid, '2026-07-10T00:00:00Z', '2026-07-1' . $index . 'T00:00:00Z');
        }
        $this->entityManager->flush();

        $history = $this->loader()->load($this->userId(), $this->settings(likedCap: 2));

        self::assertSame(['C', 'B'], array_map(static fn ($line) => $line->title, $history->liked));
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
        $entry->recordText($entry->getText()->withSummary('Summary text'));
        $entry->recordText($entry->getText()->withContentHtml('<p>Content text</p>'));
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

    public function testHistoryLinesCarryTheirSharedArticle(): void
    {
        $this->feed->setUrl('https://bsky.app/profile/alice.bsky.social/rss');
        $post = $this->entry('post', '2026-07-10T00:00:00Z');
        $content = new LinkCardContent('https://news.example.com/story', 'Story', null, null, 'Example');
        $cards = self::getContainer()->get(LinkCardRepository::class);
        self::assertInstanceOf(LinkCardRepository::class, $cards);
        $cards->append($post, [SharedLinks::of($this->entityManager, $content)]);
        $state = new EntryState($this->user, $post);
        $state->markFavorite();
        $this->entityManager->persist($state);
        $this->entityManager->flush();

        $history = $this->loader()->load($this->userId(), $this->settings());

        self::assertSame('Story', $history->favorites[0]->sharedArticle?->title);
    }

    public function testAHistoryWithoutAnyEntryIsEmpty(): void
    {
        self::assertTrue($this->loader()->load($this->userId(), $this->settings())->isEmpty());
    }

    public function testOneViewedEntryMakesTheHistoryNonEmpty(): void
    {
        $state = new EntryState($this->user, $this->entry('A', '2026-07-10T00:00:00Z'));
        $state->markViewed(new \DateTimeImmutable('2026-07-10T09:00:00Z'));
        $this->entityManager->persist($state);
        $this->entityManager->flush();

        self::assertFalse($this->loader()->load($this->userId(), $this->settings())->isEmpty());
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

    /** The share is persisted here; the returned state row is the caller's to flag and persist. */
    private function sharedState(string $guid, string $published, string $sharedAt): EntryState
    {
        $entry = $this->entry($guid, $published);
        $this->entityManager->persist(new EntryShare($this->user, $entry, new \DateTimeImmutable($sharedAt)));

        return new EntryState($this->user, $entry);
    }

    /** The like is persisted here; the returned state row is the caller's to flag. */
    private function likeEntry(string $guid, string $published, string $likedAt): EntryState
    {
        $entry = $this->entry($guid, $published);
        LikedPostFixtures::persistLike(
            $this->entityManager,
            $this->user,
            LikedPostFixtures::linkNewItem($this->entityManager, $entry, CanonicalItemKind::Mastodon, 'key-' . $guid),
            new \DateTimeImmutable($likedAt),
        );
        $state = new EntryState($this->user, $entry);
        $this->entityManager->persist($state);

        return $state;
    }

    private function settings(
        int $favoritesCap = 40,
        int $keptCap = 40,
        int $viewedCap = 80,
        int $sharedCap = 40,
        int $likedCap = 40,
    ): EffectiveRecommendationSettingsModel {
        return new EffectiveRecommendationSettingsModel(
            guidancePrompt: null,
            historyCaps: new RecommendationHistoryCaps($favoritesCap, $keptCap, $viewedCap, $sharedCap, $likedCap),
            poolLimits: new RecommendationPoolLimits(500, RecommendationSettings::DEFAULT_LOOKBACK_DAYS, 50),
            packing: new RecommendationPackingSettingsModel(
                contextWindow: 32768,
                contextWindowSource: 'fallback',
                batchSize: RecommendationBatchSize::Medium,
                maximumBatchSize: RecommendationPackingSettingsModel::DEFAULT_MAXIMUM_BATCH_SIZE,
            ),
            debugEnabled: false,
            autoGenerateIntervalHours: null,
            showScoreAndReasons: false,
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
