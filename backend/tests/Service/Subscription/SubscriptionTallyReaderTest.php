<?php

declare(strict_types=1);

namespace App\Tests\Service\Subscription;

use App\Entity\Entry;
use App\Entity\EntryState;
use App\Entity\Feed;
use App\Entity\RecommendationItem;
use App\Entity\RecommendationRun;
use App\Entity\Subscription;
use App\Entity\Tag;
use App\Entity\User;
use App\Enum\RecommendationEngineKind;
use App\Enum\SourceFormat;
use App\Service\Ai\Crypto\ApiKeyCipher;
use App\Service\Subscription\SubscriptionTallyReader;
use App\Tests\DbTestCase;
use App\Tests\Support\RecommendationRunFixtures;

final class SubscriptionTallyReaderTest extends DbTestCase
{
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
        $tallies = $reader->forUser($user->requireId());

        self::assertSame([$subscription->requireId() => 1], $tallies->unreadCounts);
        self::assertSame(1, $tallies->unreadTotals->allItems);
        self::assertSame([$subscription->requireId() => 2], $tallies->entryCounts);
        self::assertSame(['favorites' => 1, 'kept' => 0, 'viewed' => 0], $tallies->flagCounts);
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
        $totals = $reader->forUser($user->requireId())->unreadTotals;

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

        self::assertSame(1, $reader->forUser($user->requireId())->unreadTotals->forYou);
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
