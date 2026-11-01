<?php

declare(strict_types=1);

namespace App\Tests\Service\Social;

use App\Entity\Entry;
use App\Entity\Feed;
use App\Entity\SocialAccount;
use App\Entity\User;
use App\Enum\SocialNetwork;
use App\Repository\SocialAccountRepository;
use App\Service\Comments\CommentsCache;
use App\Service\Comments\CommentsLoader;
use App\Service\Social\ActingAccountChooser;
use App\Service\Social\ActingAccountGuard;
use App\Service\Social\Exception\NotASocialPostException;
use App\Service\Social\Exception\SocialAccountNotFoundException;
use App\Service\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\Exception\SocialPostNotFoundException;
use App\Service\Social\Exception\SocialPostRefusedException;
use App\Service\Social\Model\PublishedPostModel;
use App\Service\Social\Model\ResponseContextModel;
use App\Service\Social\Model\PostDraftModel;
use App\Service\Social\Model\ResponseTargetModel;
use App\Service\Social\Model\SocialCountsModel;
use App\Service\Social\Model\SocialPostAddressModel;
use App\Service\Social\PostWriting;
use App\Service\Social\ResponseTargetFinder;
use App\Service\Social\SocialAccountForUser;
use App\Service\Social\SocialCountsCache;
use App\Service\Social\SocialPostFinder;
use App\Service\Social\SocialResponses;
use App\Tests\DbTestCase;
use App\Tests\Support\AssertsRefusal;
use App\Tests\Support\FakeCommentsSource;
use App\Tests\Support\FakeMastodonNetwork;
use App\Tests\Support\SeedsUsers;
use App\Tests\Support\SocialAccounts;
use App\Tests\Support\SocialFeeds;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\DependencyInjection\ServiceLocator;

final class SocialResponsesTest extends DbTestCase
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
        $this->countsCache = new SocialCountsCache(new ArrayAdapter());
        $this->owner = $this->user('social-replies@example.test');
        $this->account = SocialFeeds::persistedAccount($this->entityManager, SocialAccounts::mastodonOf($this->owner));
    }

    public function testAReplyAnswersTheEntrysPostAsTheActingAccount(): void
    {
        $target = new ResponseTargetModel($this->post());

        $published = $this->responses()->reply($this->owner, $target, new PostDraftModel('Hi'));

        self::assertSame(['resolve ' . self::STATUS_URL, 'reply 110 Hi'], $this->mastodon->calls);
        self::assertEquals(
            new PublishedPostModel(FakeMastodonNetwork::REPLY_URI, FakeMastodonNetwork::REPLY_URI),
            $published,
        );
    }

    public function testAReplyToAReplyAnswersThatReply(): void
    {
        $this->responses()->reply(
            $this->owner,
            new ResponseTargetModel($this->post(), self::REPLY_URI),
            new PostDraftModel('Hi'),
        );

        self::assertSame(['resolve ' . self::REPLY_URI, 'reply 110 Hi'], $this->mastodon->calls);
    }

    public function testAReplyOnAChosenAccountResolvesThePostThere(): void
    {
        $second = SocialFeeds::persistedAccount(
            $this->entityManager,
            SocialAccounts::mastodonAs($this->owner, 'bea@mastodon.example'),
        );
        $resolvedOn = [];
        $this->mastodon->onResolve(static function (SocialAccount $account) use (&$resolvedOn): void {
            $resolvedOn[] = $account->getIdentity();
        });

        $this->responses()->reply(
            $this->owner,
            new ResponseTargetModel($this->post(), accountId: $second->requireId()),
            new PostDraftModel('Hi'),
        );

        self::assertSame(['bea@mastodon.example'], $resolvedOn);
    }

    public function testAnAccountThatIsNotTheUsersOrOnAnotherNetworkCannotBeChosen(): void
    {
        $foreign = SocialFeeds::persistedAccount(
            $this->entityManager,
            SocialAccounts::mastodonOf($this->user('someone-else@example.test')),
        );
        $bluesky = SocialFeeds::persistedAccount($this->entityManager, SocialAccounts::blueskyOf($this->owner));
        $entry = $this->post();

        foreach ([$foreign, $bluesky] as $account) {
            $this->assertRefused(
                fn (): mixed => $this->responses()->reply(
                    $this->owner,
                    new ResponseTargetModel($entry, accountId: $account->requireId()),
                    new PostDraftModel('Hi'),
                ),
                SocialAccountNotFoundException::class,
                'Only an account of the user on the entry\'s network can answer.',
            );
        }
        self::assertSame([], $this->mastodon->calls);
    }

    public function testAPostThatTakesNoRepliesIsRefusedBeforeTheReply(): void
    {
        $this->mastodon->knowsTheResponseContext(new ResponseContextModel(canReply: false));

        $this->assertRefused(
            fn (): mixed => $this->responses()->reply(
                $this->owner,
                new ResponseTargetModel($this->post()),
                new PostDraftModel('Hi'),
            ),
            SocialPostRefusedException::class,
            'A post that takes no replies cannot be answered.',
        );
        self::assertSame(['resolve ' . self::STATUS_URL], $this->mastodon->calls);
    }

    public function testAReplyDropsTheCachedCountsAndTheCachedThread(): void
    {
        $entry = $this->post();
        $this->countsCache->remember(self::address(), new SocialCountsModel(4, 1, 0, null));
        $this->comments->load($this->owner, $entry);

        $this->responses()->reply($this->owner, new ResponseTargetModel($entry), new PostDraftModel('Hi'));
        $this->comments->load($this->owner, $entry);

        self::assertNull($this->countsCache->find(self::address()));
        self::assertCount(2, $this->thread->fetches);
    }

    public function testAnAddressThatIsNoPostOnTheEntrysNetworkIsRefusedBeforeAnyCall(): void
    {
        $this->assertRefused(
            fn (): mixed => $this->responses()->reply(
                $this->owner,
                new ResponseTargetModel($this->post(), 'at://did:plc:x/app.bsky.feed.post/1'),
                new PostDraftModel('Hi'),
            ),
            NotASocialPostException::class,
            'Only a post on the entry\'s network can be answered.',
        );
        self::assertSame([], $this->mastodon->calls);
    }

    public function testAQuoteQuotesTheEntrysPostAsTheActingAccountAndDropsItsCachedCounts(): void
    {
        $entry = $this->post();
        $this->countsCache->remember(self::address(), new SocialCountsModel(4, 1, 0, 2));

        $target = new ResponseTargetModel($entry);

        $published = $this->responses()->quote($this->owner, $target, new PostDraftModel('Read'));

        self::assertSame(['resolve ' . self::STATUS_URL, 'quote 110 Read'], $this->mastodon->calls);
        self::assertEquals(
            new PublishedPostModel(FakeMastodonNetwork::REPLY_URI, FakeMastodonNetwork::REPLY_URI),
            $published,
        );
        self::assertNull($this->countsCache->find(self::address()));
    }

    public function testAQuoteOfAReplyQuotesThatReplyDropsTheCachedThreadAndKeepsTheEntrysCounts(): void
    {
        $entry = $this->post();
        $this->countsCache->remember(self::address(), new SocialCountsModel(4, 1, 0, 2));
        $this->comments->load($this->owner, $entry);

        $this->responses()->quote(
            $this->owner,
            new ResponseTargetModel($entry, self::REPLY_URI),
            new PostDraftModel('Read'),
        );
        $this->comments->load($this->owner, $entry);

        self::assertSame(['resolve ' . self::REPLY_URI, 'quote 110 Read'], $this->mastodon->calls);
        self::assertNotNull($this->countsCache->find(self::address()));
        self::assertCount(2, $this->thread->fetches);
    }

    public function testAPostItsAuthorKeepsFromQuotesIsRefusedBeforeTheQuote(): void
    {
        $this->mastodon->knowsTheResponseContext(new ResponseContextModel(canQuote: false));

        $this->assertRefused(
            fn (): mixed => $this->responses()->quote(
                $this->owner,
                new ResponseTargetModel($this->post()),
                new PostDraftModel('Read'),
            ),
            SocialPostRefusedException::class,
            'A post its author keeps from quotes cannot be quoted.',
        );
        self::assertSame(['resolve ' . self::STATUS_URL], $this->mastodon->calls);
    }

    public function testAReplyAConnectedAccountWroteIsDeletedAndTheCachesDropped(): void
    {
        $entry = $this->post();
        $this->mastodon->knowsTheResponseContext(new ResponseContextModel(authorIdentity: 'Alice@Mastodon.Example'));
        $this->countsCache->remember(self::address(), new SocialCountsModel(4, 1, 1, null));
        $this->comments->load($this->owner, $entry);

        $this->responses()->delete($this->owner, $entry, self::REPLY_URI);
        $this->comments->load($this->owner, $entry);

        self::assertSame(['resolve ' . self::REPLY_URI, 'delete 110'], $this->mastodon->calls);
        self::assertNull($this->countsCache->find(self::address()));
        self::assertCount(2, $this->thread->fetches);
    }

    public function testAReplySomeoneElseWroteIsNotDeleted(): void
    {
        $this->mastodon->knowsTheResponseContext(new ResponseContextModel(authorIdentity: 'ann@hachyderm.io'));

        $this->assertRefused(
            fn () => $this->responses()->delete($this->owner, $this->post(), self::REPLY_URI),
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
        $this->mastodon->knowsTheResponseContext(new ResponseContextModel(authorIdentity: 'bea@mastodon.example'));
        $this->mastodon->onResolve(function (SocialAccount $account): void {
            if ($account === $this->account) {
                throw new SocialNetworkUnreachableException('The instance is down.');
            }
        });

        $this->responses()->delete($this->owner, $this->post(), self::REPLY_URI);

        self::assertSame(
            ['resolve ' . self::REPLY_URI, 'resolve ' . self::REPLY_URI, 'delete 110'],
            $this->mastodon->calls,
        );
    }

    public function testAReplyNoAccountFindsAnyMoreCountsAsDeleted(): void
    {
        $entry = $this->post();
        $this->countsCache->remember(self::address(), new SocialCountsModel(4, 1, 1, null));
        $this->mastodon->onResolve(static function (): void {
            throw new SocialPostNotFoundException('Gone.');
        });

        $this->responses()->delete($this->owner, $entry, self::REPLY_URI);

        self::assertSame(['resolve ' . self::REPLY_URI], $this->mastodon->calls);
        self::assertNull($this->countsCache->find(self::address()));
    }

    public function testAnAccountThatCannotReachTheReplyOutranksOneThatDidNotWriteIt(): void
    {
        SocialFeeds::persistedAccount(
            $this->entityManager,
            SocialAccounts::mastodonAs($this->owner, 'bea@mastodon.example'),
        );
        $this->mastodon->knowsTheResponseContext(new ResponseContextModel(authorIdentity: 'ann@hachyderm.io'));
        $this->mastodon->onResolve(function (SocialAccount $account): void {
            if ($account !== $this->account) {
                throw new SocialNetworkUnreachableException('The instance is down.');
            }
        });

        $this->expectException(SocialNetworkUnreachableException::class);

        $this->responses()->delete($this->owner, $this->post(), self::REPLY_URI);
    }

    public function testAnAccountThatNeedsReconnectingIsNotAskedToDelete(): void
    {
        $this->account->markNeedsReconnect();
        $this->entityManager->flush();
        $this->mastodon->knowsTheResponseContext(new ResponseContextModel(authorIdentity: 'alice@mastodon.example'));

        $this->assertRefused(
            fn () => $this->responses()->delete($this->owner, $this->post(), self::REPLY_URI),
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
        $entry = new Entry($feed, 'tag:mastodon.social,2026:110', self::STATUS_URL, 'Post', $now, $now);
        $this->entityManager->persist($entry);
        $this->entityManager->flush();

        return $entry;
    }

    private static function address(): SocialPostAddressModel
    {
        return new SocialPostAddressModel(SocialNetwork::Mastodon, self::STATUS_URL);
    }

    private function responses(): SocialResponses
    {
        /** @var SocialAccountRepository $accounts */
        $accounts = $this->entityManager->getRepository(SocialAccount::class);
        $guard = new ActingAccountGuard($this->entityManager);
        $mastodon = $this->mastodon;
        $network = new ServiceLocator(['mastodon' => static fn (): FakeMastodonNetwork => $mastodon]);
        $chooser = new ActingAccountChooser(SocialFeeds::repository($this->entityManager), $accounts);
        $posts = new SocialPostFinder($chooser, $guard, $network);

        return new SocialResponses(
            new ResponseTargetFinder($posts, $chooser, new SocialAccountForUser($accounts)),
            $posts,
            new PostWriting($guard, $network),
            $accounts,
            $this->countsCache,
            $this->comments,
        );
    }
}
