<?php

declare(strict_types=1);

namespace App\Tests\Service\Social;

use App\Entity\SocialAccount;
use App\Entity\User;
use App\Enum\SocialNetwork;
use App\Repository\SocialAccountRepository;
use App\Service\Image\ImageResizer\ImageResizerInterface;
use App\Service\LinkCard\PageCardReader\PageCardReaderInterface;
use App\Service\Social\ActingAccountChooser;
use App\Service\Social\ActingAccountGuard;
use App\Service\Social\BlueskyFacets;
use App\Service\Social\BlueskyLinkCards;
use App\Service\Social\Exception\NoSocialAccountForNetworkException;
use App\Service\Social\Exception\SocialAccountNotFoundException;
use App\Service\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\Exception\SocialPostNotFoundException;
use App\Service\Social\Exception\SocialPostRefusedException;
use App\Service\Social\LinkPosts;
use App\Service\Social\Model\ComposerDefaultsModel;
use App\Service\Social\Model\LinkPostModel;
use App\Service\Social\Model\LinkTargetModel;
use App\Service\Social\Model\PostDraftModel;
use App\Service\Social\Model\PostPreviewModel;
use App\Service\Social\Model\PublishedPostModel;
use App\Service\Social\Model\ResponseContextModel;
use App\Service\Social\PostResolver\BlueskyPostResolver;
use App\Service\Social\PostWriter\BlueskyPostWriter;
use App\Service\Social\PostWriting;
use App\Service\Social\SharedPostQuotes;
use App\Service\Social\SocialAccountForUser;
use App\Service\Social\SocialPostFinder;
use App\Service\Url\UrlNormalizer;
use App\Tests\DbTestCase;
use App\Tests\Support\AssertsRefusal;
use App\Tests\Support\FakeComposerDefaults;
use App\Tests\Support\FakeMastodonNetwork;
use App\Tests\Support\RoutedHttpClient;
use App\Tests\Support\SeedsUsers;
use App\Tests\Support\SocialAccounts;
use App\Tests\Support\SocialApiWiring;
use App\Tests\Support\SocialFeeds;
use App\Tests\Support\StubGuardedImageDownloader;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\HttpClient\Response\MockResponse;

final class LinkPostsTest extends DbTestCase
{
    use AssertsRefusal;
    use SeedsUsers;

    private const string ARTICLE_URL = 'https://news.example/article?id=7';
    private const string STATUS_URL = 'https://mastodon.social/@Gargron/110';
    private const string BLUESKY_POST_URL = 'https://bsky.app/profile/bob.test/post/3abc';
    private const string BOB_POST = 'at://did:plc:bob/app.bsky.feed.post/3abc';
    private const string RESOLVE_HANDLE = 'GET https://public.api.bsky.app/xrpc/com.atproto.identity.resolveHandle'
        . '?handle=bob.test';
    private const string SESSION = 'POST https://pds.example.test/xrpc/com.atproto.server.createSession';
    private const string GET_BOB_POST = 'GET https://pds.example.test/xrpc/app.bsky.feed.getPosts?uris='
        . 'at%3A%2F%2Fdid%3Aplc%3Abob%2Fapp.bsky.feed.post%2F3abc';
    private const string CREATE_RECORD = 'POST https://pds.example.test/xrpc/com.atproto.repo.createRecord';

    private FakeMastodonNetwork $mastodon;
    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mastodon = new FakeMastodonNetwork();
        $this->owner = $this->user('link-posts@example.test');
    }

    public function testTheComposerWritesAsTheNetworksDefaultAccountWithItsOwnDefaults(): void
    {
        $first = SocialFeeds::persistedAccount($this->entityManager, SocialAccounts::mastodonOf($this->owner));
        $default = SocialFeeds::persistedAccount(
            $this->entityManager,
            SocialAccounts::mastodonAs($this->owner, 'bea@mastodon.example'),
        );
        $default->becomeDefault();
        SocialFeeds::persistedAccount($this->entityManager, SocialAccounts::blueskyOf($this->owner));
        $this->entityManager->flush();

        $context = $this->linkPosts()->contextFor($this->owner, self::target(null));

        self::assertSame([$default, $first], $context->accounts);
        self::assertSame($default, $context->account);
        self::assertTrue($context->canSend);
        self::assertEquals(new ComposerDefaultsModel(500, 'public'), $context->defaults);
        self::assertNull($context->quote);
        self::assertSame([], $this->mastodon->calls);
    }

    public function testTheComposerWritesAsTheChosenAccount(): void
    {
        SocialFeeds::persistedAccount($this->entityManager, SocialAccounts::mastodonOf($this->owner));
        $chosen = SocialFeeds::persistedAccount(
            $this->entityManager,
            SocialAccounts::mastodonAs($this->owner, 'bea@mastodon.example'),
        );

        $context = $this->linkPosts()->contextFor($this->owner, self::target($chosen->requireId()));

        self::assertSame($chosen, $context->account);
    }

    public function testALinkPostSharesTheNormalisedAddressAsTheDefaultAccount(): void
    {
        SocialFeeds::persistedAccount($this->entityManager, SocialAccounts::mastodonOf($this->owner));

        $published = $this->linkPosts()->share(
            $this->owner,
            self::post(null, self::ARTICLE_URL . '&utm_source=x', 'Worth reading'),
        );

        self::assertSame(['share ' . self::ARTICLE_URL . ' Worth reading'], $this->mastodon->calls);
        self::assertEquals(
            new PublishedPostModel(FakeMastodonNetwork::REPLY_URI, FakeMastodonNetwork::REPLY_URI),
            $published,
        );
    }

    public function testTheComposerForAStatusOnTheAccountsNetworkShowsTheStatusItQuotes(): void
    {
        SocialFeeds::persistedAccount($this->entityManager, SocialAccounts::mastodonOf($this->owner));
        $preview = new PostPreviewModel('Eugen', '@Gargron@mastodon.social', 'Hello');
        $this->mastodon->knowsTheResponseContext(new ResponseContextModel(preview: $preview));

        $context = $this->linkPosts()->contextFor($this->owner, self::target(null, self::STATUS_URL));

        self::assertSame($preview, $context->quote);
        self::assertSame(['resolve ' . self::STATUS_URL], $this->mastodon->calls);
    }

    public function testTheComposerForAStatusThatTakesNoQuoteShowsNone(): void
    {
        SocialFeeds::persistedAccount($this->entityManager, SocialAccounts::mastodonOf($this->owner));
        $this->mastodon->knowsTheResponseContext(new ResponseContextModel(canQuote: false));

        $context = $this->linkPosts()->contextFor($this->owner, self::target(null, self::STATUS_URL));

        self::assertNull($context->quote);
    }

    public function testAStatusSharedOnItsOwnNetworkIsQuoted(): void
    {
        SocialFeeds::persistedAccount($this->entityManager, SocialAccounts::mastodonOf($this->owner));

        $published = $this->linkPosts()->share($this->owner, self::post(null, self::STATUS_URL, 'Look'));

        self::assertSame(
            ['resolve ' . self::STATUS_URL, 'quote ' . FakeMastodonNetwork::STATUS_ID . ' Look'],
            $this->mastodon->calls,
        );
        self::assertEquals(
            new PublishedPostModel(FakeMastodonNetwork::REPLY_URI, FakeMastodonNetwork::REPLY_URI),
            $published,
        );
    }

    public function testAStatusWhoseAuthorForbidsQuotingIsSharedAsALink(): void
    {
        SocialFeeds::persistedAccount($this->entityManager, SocialAccounts::mastodonOf($this->owner));
        $this->mastodon->knowsTheResponseContext(new ResponseContextModel(canQuote: false));

        $this->linkPosts()->share($this->owner, self::post(null, self::STATUS_URL, 'Look'));

        self::assertSame(
            ['resolve ' . self::STATUS_URL, 'share ' . self::STATUS_URL . ' Look'],
            $this->mastodon->calls,
        );
    }

    /** @return iterable<string, array{\Throwable}> */
    public static function unresolvedStatuses(): iterable
    {
        yield 'gone' => [new SocialPostNotFoundException('The post is no longer on Mastodon.')];
        yield 'unreachable' => [new SocialNetworkUnreachableException('The instance did not answer.')];
    }

    #[DataProvider('unresolvedStatuses')]
    public function testAStatusTheAccountCannotFindIsSharedAsALink(\Throwable $failure): void
    {
        SocialFeeds::persistedAccount($this->entityManager, SocialAccounts::mastodonOf($this->owner));
        $this->mastodon->onResolve(static fn () => throw $failure);

        $this->linkPosts()->share($this->owner, self::post(null, self::STATUS_URL, 'Look'));

        self::assertSame(
            ['resolve ' . self::STATUS_URL, 'share ' . self::STATUS_URL . ' Look'],
            $this->mastodon->calls,
        );
    }

    public function testABlueskyPostSharedOnMastodonIsALinkPost(): void
    {
        SocialFeeds::persistedAccount($this->entityManager, SocialAccounts::mastodonOf($this->owner));

        $this->linkPosts()->share($this->owner, self::post(null, self::BLUESKY_POST_URL, 'Look'));

        self::assertSame(['share ' . self::BLUESKY_POST_URL . ' Look'], $this->mastodon->calls);
    }

    public function testABlueskyPostSharedOnBlueskyIsQuotedByItsAuthorsDidWithoutReadingThePage(): void
    {
        SocialFeeds::persistedAccount($this->entityManager, SocialAccounts::blueskyOf($this->owner));
        $http = new RoutedHttpClient([
            self::RESOLVE_HANDLE => new MockResponse('{"did":"did:plc:bob"}'),
            self::SESSION => SocialApiWiring::blueskySessionAnswer('jwt-1'),
            self::GET_BOB_POST => new MockResponse(
                '{"posts":[{"uri":"' . self::BOB_POST . '","cid":"bafy-bob"}]}',
            ),
            self::CREATE_RECORD => new MockResponse(
                '{"uri":"at://did:plc:alice/app.bsky.feed.post/3new","cid":"bafy-new"}',
            ),
        ]);
        $pageCards = $this->createMock(PageCardReaderInterface::class);
        $pageCards->expects($this->never())->method('read');

        $this->blueskyLinkPosts($http, $pageCards)->share(
            $this->owner,
            new LinkPostModel(
                new LinkTargetModel(SocialNetwork::Bluesky, null, self::BLUESKY_POST_URL),
                new PostDraftModel('Look'),
            ),
        );

        $lastRequest = end($http->requests);
        self::assertIsArray($lastRequest);
        self::assertSame(self::CREATE_RECORD, $lastRequest['method'] . ' ' . $lastRequest['url']);
        $created = json_decode($lastRequest['body'], true);
        self::assertIsArray($created);
        self::assertIsArray($created['record']);
        self::assertSame(
            ['$type' => 'app.bsky.embed.record', 'record' => ['uri' => self::BOB_POST, 'cid' => 'bafy-bob']],
            $created['record']['embed'],
        );
    }

    public function testAnAccountOnAnotherNetworkOrOfAnotherUserCannotBeChosen(): void
    {
        SocialFeeds::persistedAccount($this->entityManager, SocialAccounts::mastodonOf($this->owner));
        $bluesky = SocialFeeds::persistedAccount($this->entityManager, SocialAccounts::blueskyOf($this->owner));
        $foreign = SocialFeeds::persistedAccount(
            $this->entityManager,
            SocialAccounts::mastodonOf($this->user('someone-else@example.test')),
        );

        foreach ([$bluesky, $foreign] as $account) {
            $this->assertRefused(
                fn (): mixed => $this->linkPosts()->share($this->owner, self::post($account->requireId())),
                SocialAccountNotFoundException::class,
                'Only an account of the user on the asked network can post the link.',
            );
        }
        self::assertSame([], $this->mastodon->calls);
    }

    public function testAUserWithoutAnAccountOnTheNetworkCannotPostTheLink(): void
    {
        SocialFeeds::persistedAccount($this->entityManager, SocialAccounts::blueskyOf($this->owner));

        $this->expectException(NoSocialAccountForNetworkException::class);

        $this->linkPosts()->contextFor($this->owner, self::target(null));
    }

    /** @return iterable<string, array{string}> */
    public static function noLinks(): iterable
    {
        yield 'a blank address' => [''];
        yield 'no address at all' => ['not a url'];
    }

    #[DataProvider('noLinks')]
    public function testAnAddressTheNormaliserRejectsIsRefusedBeforeAnyAccountIsLookedUp(string $url): void
    {
        $missingAccountId = 999999;

        $this->assertRefused(
            fn (): mixed => $this->linkPosts()->share($this->owner, self::post($missingAccountId, $url)),
            SocialPostRefusedException::class,
            'An address that is no link is refused before the account is looked for.',
        );
        self::assertSame([], $this->mastodon->calls);
    }

    private static function target(?int $accountId, string $url = self::ARTICLE_URL): LinkTargetModel
    {
        return new LinkTargetModel(SocialNetwork::Mastodon, $accountId, $url);
    }

    private static function post(?int $accountId, string $url = self::ARTICLE_URL, string $text = 'Hi'): LinkPostModel
    {
        return new LinkPostModel(self::target($accountId, $url), new PostDraftModel($text));
    }

    private function linkPosts(): LinkPosts
    {
        $mastodon = $this->mastodon;
        $network = new ServiceLocator(['mastodon' => static fn (): FakeMastodonNetwork => $mastodon]);

        return $this->linkPostsOver($network, $network, new RoutedHttpClient([]));
    }

    private function blueskyLinkPosts(RoutedHttpClient $http, PageCardReaderInterface $pageCards): LinkPosts
    {
        $client = $http->client();
        $resolver = new BlueskyPostResolver(SocialApiWiring::blueskySessions($client));
        $writer = new BlueskyPostWriter(
            SocialApiWiring::blueskyRecords($client),
            new BlueskyFacets(SocialApiWiring::blueskyIdentityResolver($client)),
            new BlueskyLinkCards(
                $pageCards,
                new StubGuardedImageDownloader(),
                $this->createStub(ImageResizerInterface::class),
                SocialApiWiring::blueskySessions($client),
                new NullLogger(),
            ),
        );

        return $this->linkPostsOver(
            new ServiceLocator(['bluesky' => static fn (): BlueskyPostResolver => $resolver]),
            new ServiceLocator(['bluesky' => static fn (): BlueskyPostWriter => $writer]),
            $http,
        );
    }

    private function linkPostsOver(
        ContainerInterface $resolvers,
        ContainerInterface $writers,
        RoutedHttpClient $http,
    ): LinkPosts {
        /** @var SocialAccountRepository $accounts */
        $accounts = $this->entityManager->getRepository(SocialAccount::class);
        $guard = new ActingAccountGuard($this->entityManager);
        $defaults = static fn (): FakeComposerDefaults => new FakeComposerDefaults();

        return new LinkPosts(
            $accounts,
            new SocialAccountForUser($accounts),
            new UrlNormalizer(),
            new PostWriting($guard, $writers),
            new SharedPostQuotes(
                new SocialPostFinder(
                    new ActingAccountChooser(SocialFeeds::repository($this->entityManager), $accounts),
                    $guard,
                    $resolvers,
                ),
                SocialApiWiring::blueskyIdentityResolver($http->client()),
            ),
            new ServiceLocator(['mastodon' => $defaults, 'bluesky' => $defaults]),
        );
    }
}
