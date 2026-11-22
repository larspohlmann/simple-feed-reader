<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\Social;

use App\Entity\Entry;
use App\Entity\Feed;
use App\Entity\SocialAccount;
use App\Entity\Subscription;
use App\Entity\User;
use App\Repository\SocialAccountRepository;
use App\Service\Content\Comments\CommentsCache;
use App\Service\Content\Comments\CommentsLoader;
use App\Service\Social\Social\ActingAccountGuard;
use App\Service\Social\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\Social\Exception\SocialPostNotFoundException;
use App\Service\Social\Social\Exception\SocialPostRefusedException;
use App\Service\Social\Social\Model\PostContextModel;
use App\Service\Social\Social\Model\SocialCountsModel;
use App\Service\Social\Social\OwnReplyDeletion;
use App\Service\Social\Social\PostDeletions;
use App\Service\Social\Social\SocialCountsCache;
use App\Service\Social\Social\SocialPostFinder;
use App\Tests\DbTestCase;
use App\Tests\Support\AssertsRefusal;
use App\Tests\Support\FakeCommentsSource;
use App\Tests\Support\FakeMastodonNetwork;
use App\Tests\Support\SeedsUsers;
use App\Tests\Support\SocialAccounts;
use App\Tests\Support\SocialFeeds;
use App\Tests\Support\SocialRuleWiring;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\DependencyInjection\ServiceLocator;

final class OwnReplyDeletionTest extends DbTestCase
{
    use AssertsRefusal;
    use SeedsUsers;

    private const string FEED_URL = 'https://mastodon.social/@Gargron.rss';
    private const string STATUS_URL = 'https://mastodon.social/@Gargron/110';
    private const string REPLY_URI = 'https://mastodon.example/users/alice/statuses/200';

    private FakeMastodonNetwork $mastodon;
    private FakeCommentsSource $thread;
    private CommentsLoader $comments;
    private SocialCountsCache $countsCache;
    private User $owner;
    private SocialAccount $account;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mastodon = new FakeMastodonNetwork();
        $this->thread = new FakeCommentsSource('thread');
        $clock = new MockClock('2026-11-01 12:00:00');
        $this->comments = new CommentsLoader(
            [$this->thread],
            new CommentsCache(new ArrayAdapter(clock: $clock), 7200, 60),
            $clock,
        );
        $this->countsCache = new SocialCountsCache(new ArrayAdapter(), SocialRuleWiring::postAddresses());
        $this->owner = $this->user('social-replies@example.test');
        $this->account = SocialFeeds::persistedAccount($this->entityManager, SocialAccounts::mastodonOf($this->owner));
    }

    public function testAReplyAConnectedAccountWroteIsDeletedAndTheCachesDropped(): void
    {
        $entry = $this->post();
        $this->mastodon->knowsThePostContext(new PostContextModel(authorIdentity: 'Alice@Mastodon.Example'));
        $this->countsCache->remember($entry, new SocialCountsModel(4, 1, 1, null));
        $this->comments->load($this->owner, $entry);

        $this->deletion()->delete($this->owner, $entry, self::REPLY_URI);
        $this->comments->load($this->owner, $entry);

        self::assertSame(['resolve ' . self::REPLY_URI, 'delete 110'], $this->mastodon->calls);
        self::assertNull($this->countsCache->find($entry));
        self::assertCount(2, $this->thread->fetches);
    }

    public function testAReplySomeoneElseWroteIsNotDeleted(): void
    {
        $this->mastodon->knowsThePostContext(new PostContextModel(authorIdentity: 'ann@hachyderm.io'));

        $this->assertRefused(
            fn () => $this->deletion()->delete($this->owner, $this->post(), self::REPLY_URI),
            SocialPostRefusedException::class,
            'Only a reply a connected account wrote can be deleted.',
        );
        self::assertSame(['resolve ' . self::REPLY_URI], $this->mastodon->calls);
    }

    public function testAnotherAccountDeletesItsReplyWhenTheFirstCannotReachIt(): void
    {
        SocialFeeds::persistedAccount(
            $this->entityManager,
            SocialAccounts::mastodonAs($this->owner, 'bea@mastodon.example'),
        );
        $this->mastodon->knowsThePostContext(new PostContextModel(authorIdentity: 'bea@mastodon.example'));
        $this->mastodon->onResolve(function (SocialAccount $account): void {
            if ($account === $this->account) {
                throw new SocialNetworkUnreachableException('The instance is down.');
            }
        });

        $this->deletion()->delete($this->owner, $this->post(), self::REPLY_URI);

        self::assertSame(
            ['resolve ' . self::REPLY_URI, 'resolve ' . self::REPLY_URI, 'delete 110'],
            $this->mastodon->calls,
        );
    }

    public function testAReplyNoAccountFindsAnyMoreCountsAsDeleted(): void
    {
        $entry = $this->post();
        $this->countsCache->remember($entry, new SocialCountsModel(4, 1, 1, null));
        $this->mastodon->onResolve(static function (): void {
            throw new SocialPostNotFoundException('Gone.');
        });

        $this->deletion()->delete($this->owner, $entry, self::REPLY_URI);

        self::assertSame(['resolve ' . self::REPLY_URI], $this->mastodon->calls);
        self::assertNull($this->countsCache->find($entry));
    }

    public function testAnAccountThatCannotReachTheReplyOutranksOneThatDidNotWriteIt(): void
    {
        SocialFeeds::persistedAccount(
            $this->entityManager,
            SocialAccounts::mastodonAs($this->owner, 'bea@mastodon.example'),
        );
        $this->mastodon->knowsThePostContext(new PostContextModel(authorIdentity: 'ann@hachyderm.io'));
        $this->mastodon->onResolve(function (SocialAccount $account): void {
            if ($account !== $this->account) {
                throw new SocialNetworkUnreachableException('The instance is down.');
            }
        });

        $this->expectException(SocialNetworkUnreachableException::class);

        $this->deletion()->delete($this->owner, $this->post(), self::REPLY_URI);
    }

    public function testAnAccountThatNeedsReconnectingIsNotAskedToDelete(): void
    {
        $this->account->markNeedsReconnect();
        $this->entityManager->flush();
        $this->mastodon->knowsThePostContext(new PostContextModel(authorIdentity: 'alice@mastodon.example'));

        $this->assertRefused(
            fn () => $this->deletion()->delete($this->owner, $this->post(), self::REPLY_URI),
            SocialPostRefusedException::class,
            'An account that needs reconnecting deletes nothing.',
        );
        self::assertSame([], $this->mastodon->calls);
    }

    private function post(): Entry
    {
        $feed = new Feed(self::FEED_URL);
        $this->entityManager->persist($feed);
        $now = new \DateTimeImmutable('2026-11-01 10:00:00');
        $this->entityManager->persist(new Subscription($this->owner, $feed, $now));
        $entry = new Entry($feed, 'tag:mastodon.social,2026:110', self::STATUS_URL, 'Post', $now, $now);
        $this->entityManager->persist($entry);
        $this->entityManager->flush();

        return $entry;
    }

    private function deletion(): OwnReplyDeletion
    {
        /** @var SocialAccountRepository $accounts */
        $accounts = $this->entityManager->getRepository(SocialAccount::class);
        $guard = new ActingAccountGuard($this->entityManager);
        $mastodon = $this->mastodon;
        $network = new ServiceLocator(['mastodon' => static fn (): FakeMastodonNetwork => $mastodon]);
        $chooser = SocialFeeds::actingAccountChooser($this->entityManager, $accounts);

        return new OwnReplyDeletion(
            new SocialPostFinder($chooser, $guard, $network, SocialRuleWiring::postAddresses()),
            new PostDeletions($guard, $network),
            $accounts,
            $this->countsCache,
            $this->comments,
            SocialRuleWiring::postAddresses(),
        );
    }
}
