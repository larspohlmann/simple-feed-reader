<?php

declare(strict_types=1);

namespace App\Tests\Service\Library\Subscription;

use App\Entity\Entry;
use App\Entity\EntryState;
use App\Entity\Feed;
use App\Entity\ForYouStreamEntry;
use App\Entity\InclusionChange;
use App\Entity\InterestTermSet;
use App\Entity\RecommendationItem;
use App\Entity\RecommendationRun;
use App\Entity\Subscription;
use App\Entity\Tag;
use App\Entity\User;
use App\Enum\RecommendationEngineKind;
use App\Enum\SourceFormat;
use App\Service\Curation\Ai\Crypto\ApiKeyCipher;
use App\Service\Library\Subscription\SubscriptionTallyReader;
use App\Tests\DbTestCase;
use App\Tests\Support\RecommendationRunFixtures;
use App\Tests\Support\RecordsQueries;

final class SubscriptionTallyReaderTest extends DbTestCase
{
    use RecordsQueries;

    public function testReadsUnreadEntryAndSurfaceCountsForTheUser(): void
    {
        $when = new \DateTimeImmutable('2026-07-01T00:00:00Z');
        $user = new User('tallies@example.com', $when);
        $this->entityManager->persist($user);
        $feed = new Feed('https://example.com/tallies.xml');
        $this->entityManager->persist($feed);
        $subscription = new Subscription($user, $feed, $when);
        $this->entityManager->persist($subscription);
        $read = $this->entry($feed, 'read');
        $this->entry($feed, 'unread');
        $state = new EntryState($user, $read);
        $state->hide($when);
        $state->markFavorite();
        $this->entityManager->persist($state);
        $this->entityManager->flush();

        $reader = self::getContainer()->get(SubscriptionTallyReader::class);
        self::assertInstanceOf(SubscriptionTallyReader::class, $reader);
        $tallies = $reader->countTallies($user);

        self::assertSame([$subscription->requireId() => 1], $tallies->unreadCounts);
        self::assertSame(1, $tallies->unreadTotals->allItems);
        self::assertSame([$subscription->requireId() => 2], $tallies->entryCounts);
        self::assertSame(['favorites' => 1, 'kept' => 0, 'viewed' => 0, 'shared' => 0], $tallies->flagCounts);
        self::assertNotSame($tallies->entryCounts, $tallies->unreadCounts);
    }

    public function testCarriesTheTagAndNetworkTotalsFromTheirOwnScopes(): void
    {
        $when = new \DateTimeImmutable('2026-07-01T00:00:00Z');
        $user = new User('tallies-scopes@example.com', $when);
        $this->entityManager->persist($user);
        $tag = new Tag($user, 'news');
        $this->entityManager->persist($tag);
        $homeFeed = new Feed('social:bluesky:1:home');
        $homeFeed->setSourceFormat(SourceFormat::BLUESKY_API);
        $profileFeed = new Feed('https://bsky.app/profile/alice.bsky.social/rss');
        $subscriptions = [];
        foreach ([$homeFeed, $profileFeed] as $feed) {
            $this->entityManager->persist($feed);
            $subscription = new Subscription($user, $feed, $when);
            $this->entityManager->persist($subscription);
            $subscriptions[] = $subscription;
        }
        $subscriptions[0]->addTag($tag);
        foreach (['home-1', 'home-2'] as $guid) {
            $this->entry($homeFeed, $guid);
        }
        $this->entry($profileFeed, 'profile-1');
        $this->entityManager->flush();

        $reader = self::getContainer()->get(SubscriptionTallyReader::class);
        self::assertInstanceOf(SubscriptionTallyReader::class, $reader);
        $totals = $reader->countTallies($user)->unreadTotals;

        self::assertSame(3, $totals->allItems);
        self::assertSame([$tag->requireId() => 2], $totals->tags);
        self::assertSame(['bluesky' => 3], $totals->networks);
        self::assertSame(['bluesky' => 1], $totals->networkProfiles);
        self::assertSame(0, $totals->forYou);
    }

    public function testCountsTheUnreadForYouPicksOnly(): void
    {
        $when = new \DateTimeImmutable('2026-07-01T00:00:00Z');
        $user = new User('tallies-for-you@example.com', $when);
        $this->entityManager->persist($user);
        $feed = new Feed('https://example.com/for-you.xml');
        $this->entityManager->persist($feed);
        $this->entityManager->persist(new Subscription($user, $feed, $when));
        $readPick = $this->entry($feed, 'read-pick');
        $unreadPick = $this->entry($feed, 'unread-pick');
        $this->entry($feed, 'not-a-pick');
        $state = new EntryState($user, $readPick);
        $state->hide($when);
        $this->entityManager->persist($state);
        $run = $this->completedRun($user);
        $this->entityManager->persist(new RecommendationItem($run, $readPick, 1, 'reason'));
        $this->entityManager->persist(new RecommendationItem($run, $unreadPick, 2, 'reason'));
        $this->entityManager->flush();

        $reader = self::getContainer()->get(SubscriptionTallyReader::class);
        self::assertInstanceOf(SubscriptionTallyReader::class, $reader);

        self::assertSame(1, $reader->countTallies($user)->unreadTotals->forYou);
    }

    public function testAUserWithoutATermSetHasNoStream(): void
    {
        $when = new \DateTimeImmutable('2026-07-01T00:00:00Z');
        $user = new User('tallies-no-terms@example.com', $when);
        $this->entityManager->persist($user);
        $feed = new Feed('https://example.com/no-terms.xml');
        $this->entityManager->persist($feed);
        $this->entityManager->persist(new Subscription($user, $feed, $when));
        $this->streamMember($user, $this->entry($feed, 'orphan-member'));
        $this->entityManager->flush();
        $reader = $this->reader();
        $this->startRecordingQueries();

        $stream = $reader->countTallies($user)->forYouStream;

        self::assertFalse($stream->available);
        self::assertSame(0, $stream->unreadCount);
        self::assertSame(0, $stream->entryCount);
        self::assertSame([], $this->recordedQueriesMatching('/for_you_stream_entry/'));
    }

    public function testTheStreamCountsItsUnreadAndAllMatches(): void
    {
        $when = new \DateTimeImmutable('2026-07-01T00:00:00Z');
        $user = new User('tallies-stream@example.com', $when);
        $this->entityManager->persist($user);
        $feed = new Feed('https://example.com/stream-tally.xml');
        $this->entityManager->persist($feed);
        $this->entityManager->persist(new Subscription($user, $feed, $when));
        $this->entityManager->persist(new InterestTermSet($user, $when, $when, 0));
        $read = $this->entry($feed, 'read-member');
        $this->streamMember($user, $read);
        $this->streamMember($user, $this->entry($feed, 'unread-member'));
        $this->entry($feed, 'not-a-member');
        $state = new EntryState($user, $read);
        $state->hide($when);
        $this->entityManager->persist($state);
        $this->entityManager->flush();

        $stream = $this->reader()->countTallies($user)->forYouStream;

        self::assertTrue($stream->available);
        self::assertSame(1, $stream->unreadCount);
        self::assertSame(2, $stream->entryCount);
    }

    public function testAFeedSwitchedOutOfForYouLeavesTheCounts(): void
    {
        $when = new \DateTimeImmutable('2026-07-01T00:00:00Z');
        $user = new User('tallies-stream-out@example.com', $when);
        $this->entityManager->persist($user);
        $feed = new Feed('https://example.com/stream-out.xml');
        $this->entityManager->persist($feed);
        $subscription = new Subscription($user, $feed, $when);
        $subscription->changeInclusions(new InclusionChange(forYou: false));
        $this->entityManager->persist($subscription);
        $this->entityManager->persist(new InterestTermSet($user, $when, $when, 0));
        $this->streamMember($user, $this->entry($feed, 'hidden-member'));
        $this->entityManager->flush();

        $stream = $this->reader()->countTallies($user)->forYouStream;

        self::assertTrue($stream->available);
        self::assertSame(0, $stream->unreadCount);
        self::assertSame(0, $stream->entryCount);
    }

    private function reader(): SubscriptionTallyReader
    {
        $reader = self::getContainer()->get(SubscriptionTallyReader::class);
        self::assertInstanceOf(SubscriptionTallyReader::class, $reader);

        return $reader;
    }

    private function streamMember(User $user, Entry $entry): void
    {
        $this->entityManager->persist(
            new ForYouStreamEntry($user, $entry, new \DateTimeImmutable('2026-11-27 12:00:00'), 1.0, 'stem'),
        );
    }

    private function completedRun(User $user): RecommendationRun
    {
        /** @var ApiKeyCipher $cipher */
        $cipher = self::getContainer()->get(ApiKeyCipher::class);
        $run = (new RecommendationRunFixtures($this->entityManager, $cipher))->createRun($user);
        $run->snapshot(RecommendationEngineKind::Llm, [[1]]);
        $run->complete(new \DateTimeImmutable('2026-08-07T09:05:00Z'));

        return $run;
    }

    private function entry(Feed $feed, string $guid): Entry
    {
        $createdAt = new \DateTimeImmutable('2026-07-01T00:00:00Z');
        $entry = new Entry($feed, $guid, null, $guid, $createdAt, $createdAt);
        $this->entityManager->persist($entry);

        return $entry;
    }
}
