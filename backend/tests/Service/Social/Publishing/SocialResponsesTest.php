<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\Publishing;

use App\Entity\Entry;
use App\Entity\Feed;
use App\Entity\LinkCardContent;
use App\Entity\SocialAccount;
use App\Entity\Subscription;
use App\Entity\User;
use App\Repository\SharingSettingsRepository;
use App\Repository\SocialAccountRepository;
use App\Service\Content\Comments\CommentsCache;
use App\Service\Content\Comments\CommentsLoader;
use App\Service\Library\Reading\EntryShareRecorder;
use App\Service\Library\Reading\EntryShares;
use App\Service\Social\Publishing\ComposerContexts;
use App\Service\Social\Publishing\Model\ComposerDefaultsModel;
use App\Service\Social\Publishing\Model\ComposerKind;
use App\Service\Social\Publishing\Model\PostDraftModel;
use App\Service\Social\Publishing\Model\PublicationModel;
use App\Service\Social\Publishing\Model\PublishedPostModel;
use App\Service\Social\Publishing\Model\ResponseTargetModel;
use App\Service\Social\Publishing\PostWriting;
use App\Service\Social\Publishing\ResponseTargetFinder;
use App\Service\Social\Publishing\SocialResponses;
use App\Service\Social\Social\ActingAccountGuard;
use App\Service\Social\Social\Exception\NotASocialPostException;
use App\Service\Social\Social\Exception\SocialAccountNotFoundException;
use App\Service\Social\Social\Exception\SocialPostNotFoundException;
use App\Service\Social\Social\Exception\SocialPostRefusedException;
use App\Service\Social\Social\Model\PostContextModel;
use App\Service\Social\Social\Model\PostPreviewModel;
use App\Service\Social\Social\Model\SocialCountsModel;
use App\Service\Social\Social\SharedPageCards;
use App\Service\Social\Social\SocialAccountForUser;
use App\Service\Social\Social\SocialCountsCache;
use App\Service\Social\Social\SocialPostFinder;
use App\Tests\DbTestCase;
use App\Tests\Support\AssertsRefusal;
use App\Tests\Support\FailingEntryShareStore;
use App\Tests\Support\FakeCommentsSource;
use App\Tests\Support\FakeComposerDefaults;
use App\Tests\Support\FakeMastodonNetwork;
use App\Tests\Support\SeedsUsers;
use App\Tests\Support\SocialAccounts;
use App\Tests\Support\SocialFeeds;
use App\Tests\Support\SocialRuleWiring;
use App\Tests\Support\StubPageCardReader;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\NullLogger;
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
        $this->countsCache = new SocialCountsCache(new ArrayAdapter(), SocialRuleWiring::postAddresses());
        $this->owner = $this->user('social-replies@example.test');
        $this->account = SocialFeeds::persistedAccount($this->entityManager, SocialAccounts::mastodonOf($this->owner));
    }

    public function testTheComposerAnswersAsTheActingAccountAmongTheConnectedOnes(): void
    {
        $first = $this->account;
        $default = SocialFeeds::persistedAccount(
            $this->entityManager,
            SocialAccounts::mastodonAs($this->owner, 'bea@mastodon.example'),
        );
        $default->becomeDefault();
        $broken = SocialFeeds::persistedAccount(
            $this->entityManager,
            SocialAccounts::mastodonAs($this->owner, 'cid@mastodon.example'),
        );
        $broken->markNeedsReconnect();
        $this->entityManager->flush();
        $this->mastodon->knowsThePostContext(new PostContextModel(canReply: false));

        $context = $this->responses()->prepareContext(
            $this->owner,
            new ResponseTargetModel($this->post()),
            ComposerKind::Reply,
        );

        self::assertSame([$default, $first], $context->accounts);
        self::assertSame($default, $context->account);
        self::assertFalse($context->canSend);
        self::assertEquals(new ComposerDefaultsModel(500, 'public', ['110', '110']), $context->defaults);
        self::assertSame(ComposerKind::Reply, $context->kind);
        self::assertSame('110 110 ', $context->text);
        self::assertSame(['resolve ' . self::STATUS_URL], $this->mastodon->calls);
    }

    public function testTheComposerShowsThePostItAnswersWithTheLinkCardItCarries(): void
    {
        $preview = new PostPreviewModel(
            'Eugen',
            '@Gargron@mastodon.social',
            'Hello',
            new LinkCardContent('https://example.org/story', 'The story', null, null, 'Example'),
        );
        $this->mastodon->knowsThePostContext(new PostContextModel(preview: $preview));

        $context = $this->responses()->prepareContext(
            $this->owner,
            new ResponseTargetModel($this->post()),
            ComposerKind::Quote,
        );

        self::assertSame($preview, $context->subject);
    }

    public function testAReplyInTheThreadAlsoLooksUpTheEntrysPostAsItsStarter(): void
    {

        $this->responses()->prepareContext(
            $this->owner,
            new ResponseTargetModel($this->post(), self::REPLY_URI),
            ComposerKind::Reply,
        );

        self::assertSame(['resolve ' . self::REPLY_URI, 'resolve ' . self::STATUS_URL], $this->mastodon->calls);
    }

    public function testAThreadStarterTheAccountCannotFindLeavesItsAuthorOut(): void
    {
        $calls = &$this->mastodon->calls;
        $this->mastodon->onResolve(static function () use (&$calls): void {
            if (\count($calls) > 1) {
                throw new SocialPostNotFoundException('The entry\'s post is gone.');
            }
        });

        $context = $this->responses()->prepareContext(
            $this->owner,
            new ResponseTargetModel($this->post(), self::REPLY_URI),
            ComposerKind::Reply,
        );

        self::assertSame(['110', 'no thread starter'], $context->defaults->mentions);
    }

    public function testAQuoteMaySendOnlyWhereTheAuthorAllowsQuotes(): void
    {
        $this->mastodon->knowsThePostContext(new PostContextModel(canQuote: false));
        $target = new ResponseTargetModel($this->post());

        self::assertFalse($this->responses()->prepareContext($this->owner, $target, ComposerKind::Quote)->canSend);
        self::assertTrue($this->responses()->prepareContext($this->owner, $target, ComposerKind::Reply)->canSend);
    }

    public function testAQuoteOfAReplyStartsWithNoMentionsAndNeverLooksUpTheThreadStarter(): void
    {

        $context = $this->responses()->prepareContext(
            $this->owner,
            new ResponseTargetModel($this->post(), self::REPLY_URI),
            ComposerKind::Quote,
        );

        self::assertSame([], $context->defaults->mentions);
        self::assertSame(ComposerKind::Quote, $context->kind);
        self::assertSame('', $context->text);
        self::assertSame(['resolve ' . self::REPLY_URI], $this->mastodon->calls);
    }

    public function testAReplyAnswersTheEntrysPostAsTheActingAccount(): void
    {
        $target = new ResponseTargetModel($this->post());

        $published = $this->responses()->respond(
            $this->owner,
            $target,
            ComposerKind::Reply,
            new PublicationModel(new PostDraftModel('Hi')),
        );

        self::assertSame(['resolve ' . self::STATUS_URL, 'reply 110 Hi'], $this->mastodon->calls);
        self::assertEquals(
            new PublishedPostModel(FakeMastodonNetwork::REPLY_URI, FakeMastodonNetwork::REPLY_URI),
            $published,
        );
    }

    public function testAReplyToAReplyAnswersThatReply(): void
    {
        $this->responses()->respond(
            $this->owner,
            new ResponseTargetModel($this->post(), self::REPLY_URI),
            ComposerKind::Reply,
            new PublicationModel(new PostDraftModel('Hi')),
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

        $this->responses()->respond(
            $this->owner,
            new ResponseTargetModel($this->post(), accountId: $second->requireId()),
            ComposerKind::Reply,
            new PublicationModel(new PostDraftModel('Hi')),
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
                fn (): mixed => $this->responses()->respond(
                    $this->owner,
                    new ResponseTargetModel($entry, accountId: $account->requireId()),
                    ComposerKind::Reply,
                    new PublicationModel(new PostDraftModel('Hi')),
                ),
                SocialAccountNotFoundException::class,
                'Only an account of the user on the entry\'s network can answer.',
            );
        }
        self::assertSame([], $this->mastodon->calls);
    }

    public function testAPostThatTakesNoRepliesIsRefusedBeforeTheReply(): void
    {
        $this->mastodon->knowsThePostContext(new PostContextModel(canReply: false));

        $this->assertRefused(
            fn (): mixed => $this->responses()->respond(
                $this->owner,
                new ResponseTargetModel($this->post()),
                ComposerKind::Reply,
                new PublicationModel(new PostDraftModel('Hi')),
            ),
            SocialPostRefusedException::class,
            'A post that takes no replies cannot be answered.',
        );
        self::assertSame(['resolve ' . self::STATUS_URL], $this->mastodon->calls);
    }

    public function testAReplyDropsTheCachedCountsAndTheCachedThread(): void
    {
        $entry = $this->post();
        $this->countsCache->remember($entry, new SocialCountsModel(4, 1, 0, null));
        $this->comments->load($this->owner, $entry);

        $target = new ResponseTargetModel($entry);

        $this->responses()->respond(
            $this->owner,
            $target,
            ComposerKind::Reply,
            new PublicationModel(new PostDraftModel('Hi')),
        );
        $this->comments->load($this->owner, $entry);

        self::assertNull($this->countsCache->find($entry));
        self::assertCount(2, $this->thread->fetches);
    }

    public function testAnAddressThatIsNoPostOnTheEntrysNetworkIsRefusedBeforeAnyCall(): void
    {
        $this->assertRefused(
            fn (): mixed => $this->responses()->respond(
                $this->owner,
                new ResponseTargetModel($this->post(), 'at://did:plc:x/app.bsky.feed.post/1'),
                ComposerKind::Reply,
                new PublicationModel(new PostDraftModel('Hi')),
            ),
            NotASocialPostException::class,
            'Only a post on the entry\'s network can be answered.',
        );
        self::assertSame([], $this->mastodon->calls);
    }

    public function testAQuoteQuotesTheEntrysPostAsTheActingAccountAndDropsItsCachedCounts(): void
    {
        $entry = $this->post();
        $this->countsCache->remember($entry, new SocialCountsModel(4, 1, 0, 2));

        $target = new ResponseTargetModel($entry);
        $publication = new PublicationModel(new PostDraftModel('Read'));

        $published = $this->responses()->respond($this->owner, $target, ComposerKind::Quote, $publication);

        self::assertSame(['resolve ' . self::STATUS_URL, 'quote 110 Read'], $this->mastodon->calls);
        self::assertEquals(
            new PublishedPostModel(FakeMastodonNetwork::REPLY_URI, FakeMastodonNetwork::REPLY_URI),
            $published,
        );
        self::assertNull($this->countsCache->find($entry));
    }

    public function testAQuoteTheNetworkAcceptedStillSucceedsWhenRecordingTheShareFails(): void
    {
        $responses = $this->responses($this->failingShareRecorder());

        $published = $responses->respond(
            $this->owner,
            new ResponseTargetModel($this->post()),
            ComposerKind::Quote,
            new PublicationModel(new PostDraftModel('Read')),
        );

        self::assertSame(FakeMastodonNetwork::REPLY_URI, $published->url);
        self::assertSame(['resolve ' . self::STATUS_URL, 'quote 110 Read'], $this->mastodon->calls);
    }

    public function testAQuoteOfAReplyQuotesThatReplyAndDropsTheCachedCountsAndThread(): void
    {
        $entry = $this->post();
        $this->countsCache->remember($entry, new SocialCountsModel(4, 1, 0, 2));
        $this->comments->load($this->owner, $entry);

        $this->responses()->respond(
            $this->owner,
            new ResponseTargetModel($entry, self::REPLY_URI),
            ComposerKind::Quote,
            new PublicationModel(new PostDraftModel('Read')),
        );
        $this->comments->load($this->owner, $entry);

        self::assertSame(['resolve ' . self::REPLY_URI, 'quote 110 Read'], $this->mastodon->calls);
        self::assertNull($this->countsCache->find($entry));
        self::assertCount(2, $this->thread->fetches);
    }

    public function testAPostItsAuthorKeepsFromQuotesIsRefusedBeforeTheQuote(): void
    {
        $this->mastodon->knowsThePostContext(new PostContextModel(canQuote: false));

        $this->assertRefused(
            fn (): mixed => $this->responses()->respond(
                $this->owner,
                new ResponseTargetModel($this->post()),
                ComposerKind::Quote,
                new PublicationModel(new PostDraftModel('Read')),
            ),
            SocialPostRefusedException::class,
            'A post its author keeps from quotes cannot be quoted.',
        );
        self::assertSame(['resolve ' . self::STATUS_URL], $this->mastodon->calls);
    }

    #[DataProvider('linkRefusals')]
    public function testALinkAnswersNoPostAndIsRefusedBeforeAnyLookup(\Closure $attempt): void
    {
        $this->assertRefused(
            fn (): mixed => $attempt($this->responses(), $this->owner, new ResponseTargetModel($this->post())),
            SocialPostRefusedException::class,
            'A link answers no post.',
        );
        self::assertSame([], $this->mastodon->calls);
    }

    /** @return iterable<string, array{\Closure}> */
    public static function linkRefusals(): iterable
    {
        yield 'opening the composer' => [
            static fn (SocialResponses $responses, User $user, ResponseTargetModel $target): mixed => $responses
                ->prepareContext($user, $target, ComposerKind::Link),
        ];
        yield 'publishing' => [
            static fn (SocialResponses $responses, User $user, ResponseTargetModel $target): mixed => $responses
                ->respond($user, $target, ComposerKind::Link, new PublicationModel(new PostDraftModel('Hi'))),
        ];
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


    private function responses(?EntryShareRecorder $shareRecorder = null): SocialResponses
    {
        /** @var SocialAccountRepository $accounts */
        $accounts = $this->entityManager->getRepository(SocialAccount::class);
        $guard = new ActingAccountGuard($this->entityManager);
        $mastodon = $this->mastodon;
        $network = new ServiceLocator(['mastodon' => static fn (): FakeMastodonNetwork => $mastodon]);
        $chooser = SocialFeeds::actingAccountChooser($this->entityManager, $accounts);
        $posts = new SocialPostFinder($chooser, $guard, $network, SocialRuleWiring::postAddresses());

        return new SocialResponses(
            new ResponseTargetFinder(
                $posts,
                $chooser,
                new SocialAccountForUser($accounts),
                SocialRuleWiring::postAddresses(),
            ),
            new ComposerContexts(
                $accounts,
                $posts,
                new SharedPageCards(new StubPageCardReader(), new NullLogger()),
                new ServiceLocator(['mastodon' => static fn (): FakeComposerDefaults => new FakeComposerDefaults()]),
                SocialRuleWiring::postAddresses(),
            ),
            new PostWriting($guard, $network),
            $this->countsCache,
            $this->comments,
            $shareRecorder ?? $this->shareRecorder(),
        );
    }

    private function failingShareRecorder(): EntryShareRecorder
    {
        return new EntryShareRecorder(
            new EntryShares(
                new FailingEntryShareStore(),
                new MockClock('2026-11-01 12:00:00'),
                $this->sharingSettings(),
            ),
            new NullLogger(),
        );
    }

    private function sharingSettings(): SharingSettingsRepository
    {
        $repository = self::getContainer()->get(SharingSettingsRepository::class);
        self::assertInstanceOf(SharingSettingsRepository::class, $repository);

        return $repository;
    }

    private function shareRecorder(): EntryShareRecorder
    {
        $recorder = self::getContainer()->get(EntryShareRecorder::class);
        self::assertInstanceOf(EntryShareRecorder::class, $recorder);

        return $recorder;
    }
}
