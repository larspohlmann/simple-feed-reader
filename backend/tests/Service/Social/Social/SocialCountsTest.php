<?php

declare(strict_types=1);

namespace App\Tests\Service\Social;

use App\Entity\Entry;
use App\Entity\Feed;
use App\Entity\SocialAccount;
use App\Entity\User;
use App\Enum\SocialNetwork;
use App\Repository\SocialAccountRepository;
use App\Service\Social\ActingAccountGuard;
use App\Service\Social\Exception\NotASocialPostException;
use App\Service\Social\Exception\PostNotPubliclyCountableException;
use App\Service\Social\Exception\SocialNetworkThrottledException;
use App\Service\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\Exception\SocialPostNotFoundException;
use App\Service\Social\Exception\SocialPostRemovedException;
use App\Service\Social\Model\SocialCountsModel;
use App\Service\Social\Model\SocialPostAddressModel;
use App\Service\Social\SocialCounts;
use App\Service\Social\SocialCountsCache;
use App\Service\Social\SocialPostFinder;
use App\Tests\DbTestCase;
use App\Tests\Support\AssertsRefusal;
use App\Tests\Support\ConfirmedDeletions;
use App\Tests\Support\FakeHomeServerCheck;
use App\Tests\Support\FakeMastodonNetwork;
use App\Tests\Support\FakePostCountsSource;
use App\Tests\Support\SeedsUsers;
use App\Tests\Support\SocialAccounts;
use App\Tests\Support\SocialFeeds;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\DependencyInjection\ServiceLocator;

final class SocialCountsTest extends DbTestCase
{
    use AssertsRefusal;
    use SeedsUsers;

    private const string STATUS_URL = 'https://mastodon.social/@Gargron/110';
    private const string FEED_URL = 'https://mastodon.social/@Gargron.rss';

    private FakePostCountsSource $publicSource;
    private FakeMastodonNetwork $mastodon;
    private FakeHomeServerCheck $homeServer;
    private SocialCountsCache $cache;
    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->publicSource = new FakePostCountsSource(new SocialCountsModel(5, 2, 1, null));
        $this->mastodon = new FakeMastodonNetwork();
        $this->homeServer = new FakeHomeServerCheck();
        $this->cache = new SocialCountsCache(new ArrayAdapter());
        $this->owner = $this->user('social-counts-service@example.test');
    }

    public function testCachedCountsAskNoSource(): void
    {
        $this->cache->remember(self::address(), new SocialCountsModel(9, 8, 7, null));

        $counts = $this->counts()->of($this->owner, $this->post());

        self::assertEquals(new SocialCountsModel(9, 8, 7, null), $counts);
        self::assertSame([], $this->publicSource->askedAddresses);
    }

    public function testThePublicAnswerIsCachedForTheNextOpen(): void
    {
        $post = $this->post();

        $this->counts()->of($this->owner, $post);
        $counts = $this->counts()->of($this->owner, $post);

        self::assertEquals(new SocialCountsModel(5, 2, 1, null), $counts);
        self::assertSame([self::STATUS_URL], $this->publicSource->askedAddresses);
        self::assertSame([], $this->mastodon->calls);
    }

    public function testRefreshedAsksTheSourceAgainAndCachesTheNewCounts(): void
    {
        $post = $this->post();
        $this->cache->remember(self::address(), new SocialCountsModel(9, 8, 7, null));

        $refreshed = $this->counts()->refreshed($this->owner, $post);
        $afterwards = $this->counts()->of($this->owner, $post);

        self::assertEquals(new SocialCountsModel(5, 2, 1, null), $refreshed);
        self::assertEquals($refreshed, $afterwards);
        self::assertSame([self::STATUS_URL], $this->publicSource->askedAddresses);
    }

    public function testAnUnreachablePublicSourceFallsBackToTheActingAccount(): void
    {
        $this->publicSource->failWith(new SocialNetworkUnreachableException('The origin is down.'));

        $this->assertTheActingAccountAnswers();
    }

    public function testAPostGoneFromThePublicSourceIsStillAskedOfTheActingAccount(): void
    {
        $this->publicSource->failWith(new SocialPostNotFoundException('Followers only.'));

        $this->assertTheActingAccountAnswers();
    }

    public function testAPostNoPublicSourceCountsIsAskedOfTheActingAccount(): void
    {
        $this->publicSource->failWith(new PostNotPubliclyCountableException('Another shape.'));

        $this->assertTheActingAccountAnswers();
    }

    public function testWithoutAnAccountThePublicFailureIsTheAnswer(): void
    {
        $failure = new SocialNetworkThrottledException('The origin asked for fewer requests.', 30);
        $this->publicSource->failWith($failure);

        try {
            $this->counts()->of($this->owner, $this->post());
            self::fail('The public failure must reach the caller.');
        } catch (SocialNetworkThrottledException $caught) {
            self::assertSame($failure, $caught);
            self::assertSame([], $this->mastodon->calls);
        }
    }

    public function testAPostNoOneFindsIsKeptWhileItsHomeServerProvesNothing(): void
    {
        $this->publicSource->failWith(new SocialPostNotFoundException('Gone from the origin.'));
        $post = $this->post();
        $postId = $post->requireId();

        try {
            $this->counts()->of($this->owner, $post);
            self::fail('A post nothing proves deleted must stay not found.');
        } catch (SocialPostNotFoundException) {
            self::assertSame([self::STATUS_URL], $this->homeServer->askedAddresses);
            $this->entityManager->clear();
            self::assertNotNull($this->entityManager->find(Entry::class, $postId));
        }
    }

    public function testAPostItsHomeServerConfirmsDeletedIsRemoved(): void
    {
        $this->publicSource->failWith(new SocialPostNotFoundException('Gone from the origin.'));
        $this->homeServer->confirmsDeletions();
        $post = $this->post();
        $postId = $post->requireId();

        try {
            $this->counts()->of($this->owner, $post);
            self::fail('A post its home server confirms deleted must be removed.');
        } catch (SocialPostRemovedException) {
            $this->entityManager->clear();
            self::assertNull($this->entityManager->find(Entry::class, $postId));
        }
    }

    public function testAnUnreachablePublicSourceWithoutAnAccountAsksNoHomeServer(): void
    {
        $this->publicSource->failWith(new SocialNetworkUnreachableException('The origin is down.'));

        try {
            $this->counts()->of($this->owner, $this->post());
            self::fail('The unreachable network must reach the caller.');
        } catch (SocialNetworkUnreachableException) {
            self::assertSame([], $this->homeServer->askedAddresses);
        }
    }

    public function testWithoutAnAccountAPostNoPublicSourceCountsIsAnUnreachableNetwork(): void
    {
        $this->publicSource->failWith(new PostNotPubliclyCountableException('Another shape.'));

        $this->expectException(SocialNetworkUnreachableException::class);

        $this->counts()->of($this->owner, $this->post());
    }

    public function testAFallbackAnswerWithoutCountsIsAnUnreachableNetwork(): void
    {
        $this->mastodonAccount();
        $publicFailure = new SocialNetworkUnreachableException('The origin is down.');
        $this->publicSource->failWith($publicFailure);

        try {
            $this->counts()->of($this->owner, $this->post());
            self::fail('A post resolved without counts must not read as counted.');
        } catch (SocialNetworkUnreachableException $caught) {
            self::assertNotSame($publicFailure, $caught);
            self::assertSame(['resolve ' . self::STATUS_URL], $this->mastodon->calls);
        }
    }

    public function testAFailureIsNotCached(): void
    {
        $post = $this->post();
        $this->publicSource->failWith(new SocialNetworkUnreachableException('The origin is down.'));
        $this->assertRefused(
            fn (): SocialCountsModel => $this->counts()->of($this->owner, $post),
            SocialNetworkUnreachableException::class,
            'The first ask must fail.',
        );
        $this->publicSource->recovers();

        self::assertEquals(new SocialCountsModel(5, 2, 1, null), $this->counts()->of($this->owner, $post));
        self::assertCount(2, $this->publicSource->askedAddresses);
    }

    public function testAnEntryThatIsNoPostAsksNothing(): void
    {
        $this->assertRefused(
            fn (): SocialCountsModel => $this->counts()->of(
                $this->owner,
                $this->entryOn('https://example.com/feed.xml', self::STATUS_URL),
            ),
            NotASocialPostException::class,
            'An ordinary article has no counts.',
        );

        self::assertSame([], $this->publicSource->askedAddresses);
    }

    private function assertTheActingAccountAnswers(): void
    {
        $this->mastodonAccount();
        $this->mastodon->knowsCounts(new SocialCountsModel(7, 1, 0, null));

        $counts = $this->counts()->of($this->owner, $this->post());

        self::assertEquals(new SocialCountsModel(7, 1, 0, null), $counts);
        self::assertSame(['resolve ' . self::STATUS_URL], $this->mastodon->calls);
        self::assertEquals($counts, $this->cache->find(self::address()));
    }

    private static function address(): SocialPostAddressModel
    {
        return new SocialPostAddressModel(SocialNetwork::Mastodon, self::STATUS_URL);
    }

    private function mastodonAccount(): SocialAccount
    {
        return SocialFeeds::persistedAccount($this->entityManager, SocialAccounts::mastodonOf($this->owner));
    }

    private function post(): Entry
    {
        return $this->entryOn(self::FEED_URL, self::STATUS_URL);
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

    private function counts(): SocialCounts
    {
        /** @var SocialAccountRepository $accounts */
        $accounts = $this->entityManager->getRepository(SocialAccount::class);
        $mastodon = $this->mastodon;
        $publicSource = $this->publicSource;

        return new SocialCounts(
            $this->cache,
            new SocialPostFinder(
                SocialFeeds::actingAccountChooser($this->entityManager, $accounts),
                new ActingAccountGuard($this->entityManager),
                new ServiceLocator(['mastodon' => static fn (): FakeMastodonNetwork => $mastodon]),
            ),
            ConfirmedDeletions::askingOf($this->entityManager, $this->homeServer),
            new ServiceLocator(['mastodon' => static fn (): FakePostCountsSource => $publicSource]),
        );
    }
}
