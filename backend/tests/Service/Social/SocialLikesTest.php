<?php

declare(strict_types=1);

namespace App\Tests\Service\Social;

use App\Entity\Entry;
use App\Entity\Feed;
use App\Entity\SocialAccount;
use App\Entity\SocialLike;
use App\Entity\User;
use App\Enum\SocialAccountStatus;
use App\Enum\SocialNetwork;
use App\Repository\SocialAccountRepository;
use App\Repository\SocialLikeRepository;
use App\Service\Clock\NaiveUtcClock;
use App\Service\Social\ActingAccountChooser;
use App\Service\Social\ActingAccountGuard;
use App\Service\Social\Exception\NoSocialAccountForNetworkException;
use App\Service\Social\Exception\NotASocialPostException;
use App\Service\Social\Exception\SocialAccountNeedsReconnectException;
use App\Service\Social\Exception\SocialCredentialsRejectedException;
use App\Service\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\Exception\SocialPostNotFoundException;
use App\Service\Social\Model\SocialCountsModel;
use App\Service\Social\Model\SocialPostAddressModel;
use App\Service\Social\PostLiking;
use App\Service\Social\SocialCountsCache;
use App\Service\Social\SocialLikes;
use App\Service\Social\SocialPostFinder;
use App\Tests\DbTestCase;
use App\Tests\Support\AssertsRefusal;
use App\Tests\Support\FakeMastodonNetwork;
use App\Tests\Support\SeedsUsers;
use App\Tests\Support\SocialAccounts;
use App\Tests\Support\SocialFeeds;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\DependencyInjection\ServiceLocator;

final class SocialLikesTest extends DbTestCase
{
    use AssertsRefusal;
    use SeedsUsers;

    private const string STATUS_URL = 'https://mastodon.social/@Gargron/110';
    private const string FEED_URL = 'https://mastodon.social/@Gargron.rss';

    private FakeMastodonNetwork $mastodon;
    private SocialCountsCache $countsCache;
    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mastodon = new FakeMastodonNetwork();
        $this->countsCache = new SocialCountsCache(new ArrayAdapter());
        $this->owner = $this->user('social-likes-service@example.test');
    }

    public function testALikeResolvesThePostLikesItAndRemembersTheLikeId(): void
    {
        $account = $this->mastodonAccount();
        $entry = $this->entryOn(self::FEED_URL, self::STATUS_URL);
        $accountId = $account->requireId();
        $entryId = $entry->requireId();

        $this->likes()->like($this->owner, $entry);

        self::assertSame(['resolve ' . self::STATUS_URL, 'like 110'], $this->mastodon->calls);
        $this->entityManager->clear();
        $like = $this->likeRepository()->findOneBy(['entry' => $entryId]);
        self::assertNotNull($like);
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
        self::assertNull($this->likeRepository()->findOneFor($this->owner, $entry));
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
        $likedAt = new \DateTimeImmutable('2026-10-13 10:00:00');
        $this->entityManager->persist(new SocialLike($this->owner, $entry, $account, '110', $likedAt));
        $this->entityManager->flush();
        $this->countsCache->remember(self::address(), new SocialCountsModel(5, 0, 0, null));

        $this->likes()->unlike($this->owner, $entry);

        self::assertNull($this->likeRepository()->findOneFor($this->owner, $entry));
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
            $this->likes()->like($this->owner, $this->entryOn('https://example.com/feed.xml', self::STATUS_URL));
            self::fail('An ordinary article must not be liked.');
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

    private function mastodonAccount(): SocialAccount
    {
        return SocialFeeds::persistedAccount($this->entityManager, SocialAccounts::mastodonOf($this->owner));
    }

    private static function address(): SocialPostAddressModel
    {
        return new SocialPostAddressModel(SocialNetwork::Mastodon, self::STATUS_URL);
    }

    private function entryOn(string $feedUrl, string $postUrl): Entry
    {
        $feed = new Feed($feedUrl);
        $this->entityManager->persist($feed);
        $now = new \DateTimeImmutable('2026-10-13 10:00:00');
        $entry = new Entry($feed, 'tag:mastodon.social,2026:110', $postUrl, 'Post', $now, $now);
        $this->entityManager->persist($entry);
        $this->entityManager->flush();

        return $entry;
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
                new ActingAccountChooser(SocialFeeds::repository($this->entityManager), $accounts),
                $guard,
                new ServiceLocator(['mastodon' => static fn (): FakeMastodonNetwork => $mastodon]),
            ),
            new PostLiking($guard, new ServiceLocator(['mastodon' => static fn (): FakeMastodonNetwork => $mastodon])),
            $this->entityManager,
            new NaiveUtcClock(new MockClock(new \DateTimeImmutable('2026-10-13T08:30:00+02:00'))),
            $this->countsCache,
        );
    }

    private function likeRepository(): SocialLikeRepository
    {
        /** @var SocialLikeRepository $repository */
        $repository = $this->entityManager->getRepository(SocialLike::class);

        return $repository;
    }
}
