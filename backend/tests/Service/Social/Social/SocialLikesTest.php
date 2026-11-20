<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\Social;

use App\Entity\CanonicalItem;
use App\Entity\Entry;
use App\Entity\Feed;
use App\Entity\SocialAccount;
use App\Entity\SocialFeed;
use App\Entity\SocialLike;
use App\Entity\User;
use App\Enum\CanonicalItemKind;
use App\Enum\SocialAccountStatus;
use App\Enum\SocialFeedKind;
use App\Enum\SocialNetwork;
use App\Enum\SourceFormat;
use App\Repository\SocialAccountRepository;
use App\Repository\SocialLikeRepository;
use App\Service\Foundation\Clock\NaiveUtcClock;
use App\Service\Social\Social\ActingAccountGuard;
use App\Service\Social\Social\Exception\NoSocialAccountForNetworkException;
use App\Service\Social\Social\Exception\NotASocialPostException;
use App\Service\Social\Social\Exception\SocialAccountNeedsReconnectException;
use App\Service\Social\Social\Exception\SocialCredentialsRejectedException;
use App\Service\Social\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\Social\Exception\SocialPostNotFoundException;
use App\Service\Social\Social\Model\SocialCountsModel;
use App\Service\Social\Social\Model\SocialPostAddressModel;
use App\Service\Social\Social\PostLiking;
use App\Service\Social\Social\SocialCountsCache;
use App\Service\Social\Social\SocialLikes;
use App\Service\Social\Social\SocialPostFinder;
use App\Tests\DbTestCase;
use App\Tests\Support\AssertsRefusal;
use App\Tests\Support\ConfirmedDeletions;
use App\Tests\Support\FakeHomeServerCheck;
use App\Tests\Support\FakeMastodonNetwork;
use App\Tests\Support\SeedsUsers;
use App\Tests\Support\SocialAccounts;
use App\Tests\Support\SocialFeeds;
use App\Tests\Support\SocialRuleWiring;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\DependencyInjection\ServiceLocator;

final class SocialLikesTest extends DbTestCase
{
    use AssertsRefusal;
    use SeedsUsers;

    private const string STATUS_URL = 'https://mastodon.social/@Gargron/110';
    private const string FEED_URL = 'https://mastodon.social/@Gargron.rss';
    private const string STATUS_KEY = 'https://mastodon.social/users/Gargron/statuses/110';

    private FakeMastodonNetwork $mastodon;
    private SocialCountsCache $countsCache;
    private User $owner;
    private ?CanonicalItem $item = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mastodon = new FakeMastodonNetwork();
        $this->countsCache = new SocialCountsCache(new ArrayAdapter(), SocialRuleWiring::postAddresses());
        $this->owner = $this->user('social-likes-service@example.test');
    }

    public function testALikeResolvesThePostLikesItAndRemembersTheLikeId(): void
    {
        $account = $this->mastodonAccount();
        $entry = $this->entryOn(self::FEED_URL, self::STATUS_URL);
        $accountId = $account->requireId();

        $this->likes()->like($this->owner, $entry);

        self::assertSame(['resolve ' . self::STATUS_URL, 'like 110'], $this->mastodon->calls);
        $this->entityManager->clear();
        $like = $this->likeRepository()->findOneBy(['user' => $this->owner->requireId()]);
        self::assertNotNull($like);
        self::assertSame($entry->getCanonicalItem()?->requireId(), $like->getCanonicalItem()->requireId());
        self::assertSame($accountId, $like->getSocialAccount()->requireId());
        self::assertSame('110', $like->getRemoteId());
        self::assertEquals(new \DateTimeImmutable('2026-10-13 06:30:00'), $like->getLikedAt());
    }

    public function testLikingALikedPostAgainCallsNoNetwork(): void
    {
        $this->mastodonAccount();
        $entry = $this->entryOn(self::FEED_URL, self::STATUS_URL);

        $this->likes()->like($this->owner, $entry);
        $this->likes()->like($this->owner, $entry);

        self::assertCount(2, $this->mastodon->calls);
    }

    public function testUnlikingTakesTheLikeBackWithItsOwnAccountAndForgetsIt(): void
    {
        $this->mastodonAccount();
        $entry = $this->entryOn(self::FEED_URL, self::STATUS_URL);
        $this->likes()->like($this->owner, $entry);

        $this->likes()->unlike($this->owner, $entry);

        self::assertSame('unlike 110', $this->mastodon->calls[2]);
        self::assertNull($this->likeRepository()->findOneFor($this->owner, $this->ensureItem()));
    }

    public function testALikeDropsThePostsCachedCounts(): void
    {
        $this->mastodonAccount();
        $entry = $this->entryOn(self::FEED_URL, self::STATUS_URL);
        $this->countsCache->remember(self::address(), new SocialCountsModel(4, 0, 0, null));

        $this->likes()->like($this->owner, $entry);

        self::assertNull($this->countsCache->find(self::address()));
    }

    public function testAnUnlikeDropsThePostsCachedCounts(): void
    {
        $this->mastodonAccount();
        $entry = $this->entryOn(self::FEED_URL, self::STATUS_URL);
        $this->likes()->like($this->owner, $entry);
        $this->countsCache->remember(self::address(), new SocialCountsModel(5, 0, 0, null));

        $this->likes()->unlike($this->owner, $entry);

        self::assertNull($this->countsCache->find(self::address()));
    }

    public function testAnUnlikeOfAnEntryThatIsNoPostLongerLeavesTheCacheAlone(): void
    {
        $account = $this->mastodonAccount();
        $entry = $this->entryOn('https://example.com/feed.xml', self::STATUS_URL);
        $this->storeLike($account, '110');
        $this->countsCache->remember(self::address(), new SocialCountsModel(5, 0, 0, null));

        $this->likes()->unlike($this->owner, $entry);

        self::assertNull($this->likeRepository()->findOneFor($this->owner, $this->ensureItem()));
        self::assertEquals(new SocialCountsModel(5, 0, 0, null), $this->countsCache->find(self::address()));
    }

    public function testAFailedLikeKeepsTheCachedCounts(): void
    {
        $this->mastodonAccount();
        $entry = $this->entryOn(self::FEED_URL, self::STATUS_URL);
        $this->countsCache->remember(self::address(), new SocialCountsModel(4, 0, 0, null));
        $this->mastodon->failWith(new SocialNetworkUnreachableException('down'));

        $this->assertRefused(
            fn () => $this->likes()->like($this->owner, $entry),
            SocialNetworkUnreachableException::class,
            'An unreachable network must reach the caller.',
        );

        self::assertEquals(new SocialCountsModel(4, 0, 0, null), $this->countsCache->find(self::address()));
    }

    public function testUnlikingKeepsTheLikeWhenTheNetworkCannotBeReached(): void
    {
        $this->mastodonAccount();
        $entry = $this->entryOn(self::FEED_URL, self::STATUS_URL);
        $this->likes()->like($this->owner, $entry);
        $this->mastodon->failWith(new SocialNetworkUnreachableException('down'));

        try {
            $this->likes()->unlike($this->owner, $entry);
            self::fail('An unreachable network must reach the caller.');
        } catch (SocialNetworkUnreachableException) {
            $this->entityManager->clear();
            self::assertSame(1, $this->likeRepository()->count([]));
        }
    }

    public function testUnlikingAPostNeverLikedCallsNoNetwork(): void
    {
        $this->mastodonAccount();

        $this->likes()->unlike($this->owner, $this->entryOn(self::FEED_URL, self::STATUS_URL));

        self::assertSame([], $this->mastodon->calls);
    }

    public function testAnEntryThatIsNoPostIsRefusedBeforeAnyCall(): void
    {
        $this->mastodonAccount();

        try {
            $this->likes()->like($this->owner, $this->articleOn('https://example.com/feed.xml'));
            self::fail('An ordinary article must not be liked.');
        } catch (NotASocialPostException) {
            self::assertSame([], $this->mastodon->calls);
        }
    }

    public function testUnlikingAnEntryWithoutAnItemIsRefusedBeforeAnyCall(): void
    {
        $this->mastodonAccount();

        try {
            $this->likes()->unlike($this->owner, $this->articleOn(self::FEED_URL));
            self::fail('An entry without an item has no like to take back.');
        } catch (NotASocialPostException) {
            self::assertSame([], $this->mastodon->calls);
        }
    }

    public function testAPostOnANetworkWithoutAnAccountIsRefused(): void
    {
        $this->expectException(NoSocialAccountForNetworkException::class);

        $this->likes()->like($this->owner, $this->entryOn(self::FEED_URL, self::STATUS_URL));
    }

    public function testAnAccountThatNeedsReconnectingIsRefusedBeforeAnyCall(): void
    {
        $this->mastodonAccount()->markNeedsReconnect();
        $this->entityManager->flush();

        try {
            $this->likes()->like($this->owner, $this->entryOn(self::FEED_URL, self::STATUS_URL));
            self::fail('An account that needs reconnecting must not act.');
        } catch (SocialAccountNeedsReconnectException) {
            self::assertSame([], $this->mastodon->calls);
        }
    }

    public function testARefusedCredentialMarksTheAccountAndStoresNoLike(): void
    {
        $accountId = $this->mastodonAccount()->requireId();
        $entry = $this->entryOn(self::FEED_URL, self::STATUS_URL);
        $this->mastodon->failWith(new SocialCredentialsRejectedException('The instance refused this access token.'));

        try {
            $this->likes()->like($this->owner, $entry);
            self::fail('A refused credential must reach the caller.');
        } catch (SocialCredentialsRejectedException) {
            $this->entityManager->clear();
            self::assertSame(
                SocialAccountStatus::NeedsReconnect,
                $this->entityManager->find(SocialAccount::class, $accountId)?->getStatus(),
            );
            self::assertSame(0, $this->likeRepository()->count([]));
        }
    }

    public function testAPostThatIsGoneStoresNoLike(): void
    {
        $this->mastodonAccount();
        $this->mastodon->failWith(new SocialPostNotFoundException('The post is no longer on Mastodon.'));

        try {
            $this->likes()->like($this->owner, $this->entryOn(self::FEED_URL, self::STATUS_URL));
            self::fail('A gone post must not read as liked.');
        } catch (SocialPostNotFoundException) {
            self::assertSame(0, $this->likeRepository()->count([]));
        }
    }

    public function testALikeMadeOnTheNetworkIsStoredWithTheIdItReports(): void
    {
        $account = $this->mastodonAccount();
        $entry = $this->entryOn(self::FEED_URL, self::STATUS_URL);
        $this->mastodon->knowsTheLike('2041');

        self::assertNotNull($this->likes()->syncFromNetwork($this->owner, $entry)->existingLikeId);

        self::assertSame(['resolve ' . self::STATUS_URL], $this->mastodon->calls);
        $like = $this->requireStoredLike();
        self::assertSame($account->requireId(), $like->getSocialAccount()->requireId());
        self::assertSame('2041', $like->getRemoteId());
        self::assertEquals(new \DateTimeImmutable('2026-10-13 06:30:00'), $like->getLikedAt());
    }

    public function testALikeTakenBackOnTheNetworkIsForgottenWithoutAnUnlike(): void
    {
        $account = $this->mastodonAccount();
        $entry = $this->entryOn(self::FEED_URL, self::STATUS_URL);
        $this->storeLike($account, '110');

        self::assertNull($this->likes()->syncFromNetwork($this->owner, $entry)->existingLikeId);

        self::assertSame(['resolve ' . self::STATUS_URL], $this->mastodon->calls);
        self::assertNull($this->likeRepository()->findOneFor($this->owner, $this->ensureItem()));
    }

    public function testAPostLikedNowhereStaysUnliked(): void
    {
        $this->mastodonAccount();
        $entry = $this->entryOn(self::FEED_URL, self::STATUS_URL);

        self::assertNull($this->likes()->syncFromNetwork($this->owner, $entry)->existingLikeId);

        self::assertSame(0, $this->likeRepository()->count([]));
    }

    public function testALikeTheNetworkConfirmsIsKeptAsItIs(): void
    {
        $account = $this->mastodonAccount();
        $entry = $this->entryOn(self::FEED_URL, self::STATUS_URL);
        $this->storeLike($account, '110');
        $this->mastodon->knowsTheLike();

        self::assertNotNull($this->likes()->syncFromNetwork($this->owner, $entry)->existingLikeId);

        self::assertEquals(new \DateTimeImmutable('2026-10-13 10:00:00'), $this->requireStoredLike()->getLikedAt());
    }

    public function testALikeStoredUnderAnotherIdIsReplacedByTheOneTheNetworkReports(): void
    {
        $account = $this->mastodonAccount();
        $entry = $this->entryOn(self::FEED_URL, self::STATUS_URL);
        $this->storeLike($account, '97');
        $this->mastodon->knowsTheLike('2041');

        self::assertNotNull($this->likes()->syncFromNetwork($this->owner, $entry)->existingLikeId);

        $like = $this->requireStoredLike();
        self::assertSame('2041', $like->getRemoteId());
        self::assertEquals(new \DateTimeImmutable('2026-10-13 06:30:00'), $like->getLikedAt());
        self::assertSame(1, $this->likeRepository()->count([]));
    }

    public function testASyncAsksTheAccountThatMadeTheLikeAndKeepsIt(): void
    {
        $this->mastodonAccount();
        $other = $this->secondMastodonAccount();
        $entry = $this->entryOn(self::FEED_URL, self::STATUS_URL);
        $this->storeLike($other, '110');
        $this->mastodon->knowsTheLike();

        self::assertNotNull($this->likes()->syncFromNetwork($this->owner, $entry)->existingLikeId);

        self::assertSame([$other->requireId()], $this->mastodon->actingAccountIds);
        self::assertSame($other->requireId(), $this->requireStoredLike()->getSocialAccount()->requireId());
    }

    public function testALikeOnOneCopyLikesTheOtherWithoutANetworkCall(): void
    {
        $this->mastodonAccount();
        $first = $this->entryOn(self::FEED_URL, self::STATUS_URL);
        $second = $this->entryOn('https://mastodon.social/@Gargron/tagged.rss', self::STATUS_URL);
        $this->likes()->like($this->owner, $first);

        $this->likes()->like($this->owner, $second);

        self::assertSame(['resolve ' . self::STATUS_URL, 'like 110'], $this->mastodon->calls);
        self::assertSame(1, $this->likeRepository()->count([]));
    }

    public function testEveryCopyActsThroughTheAccountThatMadeTheLike(): void
    {
        $liker = $this->mastodonAccount();
        $homeOwner = $this->secondMastodonAccount();
        $rssCopy = $this->entryOn(self::FEED_URL, self::STATUS_URL);
        $homeCopy = $this->persistHomeCopy($homeOwner);
        $this->likes()->like($this->owner, $rssCopy);

        $this->likes()->unlike($this->owner, $homeCopy);
        $this->storeLike($liker, '110');
        $this->mastodon->knowsTheLike();
        $this->likes()->syncFromNetwork($this->owner, $homeCopy);

        $likerId = $liker->requireId();
        self::assertSame([$likerId, $likerId, $likerId], $this->mastodon->actingAccountIds);
        self::assertSame($likerId, $this->requireStoredLike()->getSocialAccount()->requireId());
    }

    public function testWithoutALikeTheCopysOwnAccountLikes(): void
    {
        $this->mastodonAccount();
        $homeOwner = $this->secondMastodonAccount();

        $this->likes()->like($this->owner, $this->persistHomeCopy($homeOwner));

        self::assertSame([$homeOwner->requireId()], $this->mastodon->actingAccountIds);
        self::assertSame($homeOwner->requireId(), $this->requireStoredLike()->getSocialAccount()->requireId());
    }

    public function testAnUnreachableNetworkKeepsTheStoredLike(): void
    {
        $account = $this->mastodonAccount();
        $entry = $this->entryOn(self::FEED_URL, self::STATUS_URL);
        $this->storeLike($account, '110');
        $this->mastodon->failWith(new SocialNetworkUnreachableException('down'));

        $this->assertRefused(
            fn () => $this->likes()->syncFromNetwork($this->owner, $entry),
            SocialNetworkUnreachableException::class,
            'An unreachable network must reach the caller.',
        );

        $this->entityManager->clear();
        self::assertSame(1, $this->likeRepository()->count([]));
    }

    public function testALikeStoredWhileTheNetworkIsAskedSurvivesAnAnswerOfNotLiked(): void
    {
        $account = $this->mastodonAccount();
        $entry = $this->entryOn(self::FEED_URL, self::STATUS_URL);
        $this->mastodon->onResolve(fn () => $this->storeLike($account, '110'));

        self::assertNull($this->likes()->syncFromNetwork($this->owner, $entry)->existingLikeId);

        self::assertSame('110', $this->requireStoredLike()->getRemoteId());
    }

    private function mastodonAccount(): SocialAccount
    {
        return SocialFeeds::persistedAccount($this->entityManager, SocialAccounts::mastodonOf($this->owner));
    }

    private function secondMastodonAccount(): SocialAccount
    {
        return SocialFeeds::persistedAccount(
            $this->entityManager,
            SocialAccounts::mastodonAs($this->owner, 'alice2@mastodon.example'),
        );
    }

    private static function address(): SocialPostAddressModel
    {
        return new SocialPostAddressModel(SocialNetwork::Mastodon, self::STATUS_URL);
    }

    /** A copy of the post, linked to the item every copy in the test shares. */
    private function entryOn(string $feedUrl, string $postUrl): Entry
    {
        return $this->persistCopy(new Feed($feedUrl), $postUrl);
    }

    private function persistHomeCopy(SocialAccount $account): Entry
    {
        $feed = new Feed(sprintf('social:mastodon:%d:home', $account->requireId()));
        $feed->setSourceFormat(SourceFormat::MASTODON_API);
        $this->entityManager->persist(new SocialFeed($account, $feed, SocialFeedKind::Home, ''));

        return $this->persistCopy($feed, self::STATUS_URL);
    }

    private function persistCopy(Feed $feed, string $postUrl): Entry
    {
        $entry = $this->persistEntry($feed, $postUrl);
        $entry->linkTo($this->ensureItem());
        $this->entityManager->flush();

        return $entry;
    }

    private function articleOn(string $feedUrl): Entry
    {
        return $this->persistEntry(new Feed($feedUrl), self::STATUS_URL);
    }

    private function persistEntry(Feed $feed, string $url): Entry
    {
        $this->entityManager->persist($feed);
        $now = new \DateTimeImmutable('2026-10-13 10:00:00');
        $entry = new Entry($feed, 'tag:mastodon.social,2026:110', $url, 'Post', $now, $now);
        $this->entityManager->persist($entry);
        $this->entityManager->flush();

        return $entry;
    }

    private function ensureItem(): CanonicalItem
    {
        if (null === $this->item) {
            $this->item = new CanonicalItem(CanonicalItemKind::Mastodon, self::STATUS_KEY);
            $this->entityManager->persist($this->item);
        }

        return $this->item;
    }

    private function storeLike(SocialAccount $account, string $remoteId): void
    {
        $likedAt = new \DateTimeImmutable('2026-10-13 10:00:00');
        $this->entityManager->persist(
            new SocialLike($this->owner, $this->ensureItem(), $account, $remoteId, $likedAt),
        );
        $this->entityManager->flush();
    }

    private function requireStoredLike(): SocialLike
    {
        $ownerId = $this->owner->requireId();
        $this->entityManager->clear();
        $like = $this->likeRepository()->findOneBy(['user' => $ownerId]);
        self::assertNotNull($like);

        return $like;
    }

    private function likes(): SocialLikes
    {
        /** @var SocialAccountRepository $accounts */
        $accounts = $this->entityManager->getRepository(SocialAccount::class);
        $guard = new ActingAccountGuard($this->entityManager);
        $mastodon = $this->mastodon;

        return new SocialLikes(
            $this->likeRepository(),
            new SocialPostFinder(
                SocialFeeds::actingAccountChooser($this->entityManager, $accounts),
                $guard,
                new ServiceLocator(['mastodon' => static fn (): FakeMastodonNetwork => $mastodon]),
                SocialRuleWiring::postAddresses(),
            ),
            new PostLiking($guard, new ServiceLocator(['mastodon' => static fn (): FakeMastodonNetwork => $mastodon])),
            $this->entityManager,
            new NaiveUtcClock(new MockClock(new \DateTimeImmutable('2026-10-13T08:30:00+02:00'))),
            $this->countsCache,
            ConfirmedDeletions::askingOf($this->entityManager, new FakeHomeServerCheck()),
        );
    }

    private function likeRepository(): SocialLikeRepository
    {
        /** @var SocialLikeRepository $repository */
        $repository = $this->entityManager->getRepository(SocialLike::class);

        return $repository;
    }
}
