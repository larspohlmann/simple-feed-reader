<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\PostResolver;

use App\Entity\LinkCardContent;
use App\Service\Social\Exception\SocialCredentialsRejectedException;
use App\Service\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\Exception\SocialPostNotFoundException;
use App\Service\Social\Model\PostPreviewModel;
use App\Service\Social\Model\PostReferenceModel;
use App\Service\Social\Model\SocialCountsModel;
use App\Service\Social\PostResolver\BlueskyPostResolver;
use App\Tests\Support\RoutedHttpClient;
use App\Tests\Support\SocialApiWiring;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Response;

final class BlueskyPostResolverTest extends TestCase
{
    private const string POST_URI = 'at://did:plc:bob/app.bsky.feed.post/3abc';
    private const string LIKE_URI = 'at://did:plc:alice/app.bsky.feed.like/3lk2';
    private const string REPOST_URI = 'at://did:plc:alice/app.bsky.feed.repost/3rp1';
    private const string SESSION = 'POST https://pds.example.test/xrpc/com.atproto.server.createSession';
    private const string HANDLE_POST_URI = 'at://bob.test/app.bsky.feed.post/3abc';
    private const string RESOLVE_HANDLE = 'GET https://public.api.bsky.app/xrpc/com.atproto.identity.resolveHandle'
        . '?handle=bob.test';
    private const string GET_POSTS = 'GET https://pds.example.test/xrpc/app.bsky.feed.getPosts?uris='
        . 'at%3A%2F%2Fdid%3Aplc%3Abob%2Fapp.bsky.feed.post%2F3abc';

    public function testAPostResolvesToItsUriAndCidOnTheAccount(): void
    {
        $account = SocialApiWiring::blueskyAccount();
        $http = $this->answering(['posts' => [['uri' => self::POST_URI, 'cid' => 'bafy-cid']]]);

        $post = $this->resolver($http)->resolve($account, self::POST_URI);

        self::assertSame($account, $post->account);
        self::assertSame(self::POST_URI, $post->postId);
        self::assertSame('bafy-cid', $post->contentId);
        self::assertNull($post->existingLikeId);
        self::assertContains('Authorization: Bearer jwt-1', $http->requests[1]['headers']);
        self::assertNull($post->counts);
    }

    public function testAPostCarriesItsAuthorThreadRootAndReplyAndQuoteGates(): void
    {
        $http = $this->answering(['posts' => [[
            'uri' => self::POST_URI,
            'cid' => 'bafy-cid',
            'author' => ['did' => 'did:plc:bob', 'handle' => 'bob.test'],
            'record' => ['reply' => [
                'root' => ['uri' => 'at://did:plc:carol/app.bsky.feed.post/3root', 'cid' => 'bafy-root'],
                'parent' => ['uri' => 'at://did:plc:carol/app.bsky.feed.post/3root', 'cid' => 'bafy-root'],
            ]],
            'viewer' => ['replyDisabled' => true, 'embeddingDisabled' => true],
        ]]]);

        $context = $this->resolver($http)->resolve(SocialApiWiring::blueskyAccount(), self::POST_URI)->responseContext;

        self::assertSame('did:plc:bob', $context->authorIdentity);
        self::assertEquals(
            new PostReferenceModel('at://did:plc:carol/app.bsky.feed.post/3root', 'bafy-root'),
            $context->threadRoot,
        );
        self::assertFalse($context->canReply);
        self::assertFalse($context->canQuote);
        self::assertNull($context->visibility);
    }

    public function testAPostIsPreviewedByItsAuthorAndText(): void
    {
        $http = $this->answering(['posts' => [[
            'uri' => self::POST_URI,
            'cid' => 'bafy-cid',
            'author' => ['did' => 'did:plc:bob', 'handle' => 'bob.test', 'displayName' => 'Bob'],
            'record' => ['text' => 'Hello world'],
        ]]]);

        $context = $this->resolver($http)->resolve(SocialApiWiring::blueskyAccount(), self::POST_URI)->responseContext;

        self::assertEquals(new PostPreviewModel('Bob', '@bob.test', 'Hello world'), $context->preview);
    }

    /** @param array<string, mixed> $embed */
    #[DataProvider('linkCardEmbeds')]
    public function testAPostIsPreviewedWithTheLinkCardItsEmbedShows(array $embed): void
    {
        $http = $this->answering(['posts' => [[
            'uri' => self::POST_URI,
            'cid' => 'bafy-cid',
            'record' => ['text' => 'Senate passes the bill'],
            'embed' => $embed,
        ]]]);

        $context = $this->resolver($http)->resolve(SocialApiWiring::blueskyAccount(), self::POST_URI)->responseContext;

        self::assertEquals(
            new LinkCardContent(
                'https://www.politico.com/news/bill',
                'Senate passes the bill',
                'After a long night',
                'https://cdn.bsky.app/img/thumb.jpg',
                'politico.com',
            ),
            $context->preview->card,
        );
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function linkCardEmbeds(): iterable
    {
        $external = [
            '$type' => 'app.bsky.embed.external#view',
            'external' => [
                'uri' => 'https://www.politico.com/news/bill',
                'title' => 'Senate passes the bill',
                'description' => 'After a long night',
                'thumb' => 'https://cdn.bsky.app/img/thumb.jpg',
            ],
        ];

        yield 'an external embed' => [$external];
        yield 'a quote with an external embed as its media' => [[
            '$type' => 'app.bsky.embed.recordWithMedia#view',
            'record' => ['record' => ['uri' => 'at://did:plc:carol/app.bsky.feed.post/3q']],
            'media' => $external,
        ]];
    }

    /** @param array<string, mixed> $embed */
    #[DataProvider('cardlessEmbeds')]
    public function testAPostWhoseEmbedShowsNoLinkCardIsPreviewedWithoutOne(array $embed): void
    {
        $http = $this->answering(['posts' => [['uri' => self::POST_URI, 'cid' => 'bafy-cid', 'embed' => $embed]]]);

        $context = $this->resolver($http)->resolve(SocialApiWiring::blueskyAccount(), self::POST_URI)->responseContext;

        self::assertNull($context->preview->card);
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function cardlessEmbeds(): iterable
    {
        yield 'images' => [['$type' => 'app.bsky.embed.images#view', 'images' => []]];
        yield 'an external embed without a title' => [[
            '$type' => 'app.bsky.embed.external#view',
            'external' => ['uri' => 'https://www.politico.com/news/bill'],
        ]];
        yield 'a quote with images' => [[
            '$type' => 'app.bsky.embed.recordWithMedia#view',
            'media' => ['$type' => 'app.bsky.embed.images#view', 'images' => []],
        ]];
    }

    public function testAPostWithoutAuthorOrTextIsPreviewedEmpty(): void
    {
        $http = $this->answering(['posts' => [['uri' => self::POST_URI, 'cid' => 'bafy-cid']]]);

        $context = $this->resolver($http)->resolve(SocialApiWiring::blueskyAccount(), self::POST_URI)->responseContext;

        self::assertEquals(new PostPreviewModel(), $context->preview);
    }

    public function testATopLevelPostIsItsOwnThreadRootAndTakesReplies(): void
    {
        $http = $this->answering(['posts' => [['uri' => self::POST_URI, 'cid' => 'bafy-cid']]]);

        $context = $this->resolver($http)->resolve(SocialApiWiring::blueskyAccount(), self::POST_URI)->responseContext;

        self::assertNull($context->threadRoot);
        self::assertNull($context->authorIdentity);
        self::assertTrue($context->canReply);
        self::assertTrue($context->canQuote);
    }

    /** @param array<string, string> $root */
    #[DataProvider('incompleteRoots')]
    public function testAnIncompleteRootReferenceReadsAsNone(array $root): void
    {
        $http = $this->answering(['posts' => [[
            'uri' => self::POST_URI,
            'cid' => 'bafy-cid',
            'record' => ['reply' => ['root' => $root]],
        ]]]);

        $context = $this->resolver($http)->resolve(SocialApiWiring::blueskyAccount(), self::POST_URI)->responseContext;

        self::assertNull($context->threadRoot);
    }

    /** @return iterable<string, array{array<string, string>}> */
    public static function incompleteRoots(): iterable
    {
        yield 'no cid' => [['uri' => 'at://did:plc:carol/app.bsky.feed.post/3root']];
        yield 'no uri' => [['cid' => 'bafy-root']];
        yield 'not a post' => [['uri' => 'https://bsky.app/x', 'cid' => 'bafy-root']];
    }

    public function testAPostCarriesTheCountsTheAccountsServerAnswered(): void
    {
        $http = $this->answering(['posts' => [[
            'uri' => self::POST_URI,
            'cid' => 'bafy-cid',
            'likeCount' => 12,
            'repostCount' => 3,
            'replyCount' => 4,
            'quoteCount' => 1,
        ]]]);

        $post = $this->resolver($http)->resolve(SocialApiWiring::blueskyAccount(), self::POST_URI);

        self::assertEquals(new SocialCountsModel(12, 3, 4, 1), $post->counts);
    }

    public function testCountsSurviveAnExistingLike(): void
    {
        $http = $this->answering(['posts' => [[
            'uri' => self::POST_URI,
            'cid' => 'bafy-cid',
            'viewer' => ['like' => self::LIKE_URI],
            'likeCount' => 12,
            'repostCount' => 3,
            'replyCount' => 4,
        ]]]);

        $post = $this->resolver($http)->resolve(SocialApiWiring::blueskyAccount(), self::POST_URI);

        self::assertSame(self::LIKE_URI, $post->existingLikeId);
        self::assertEquals(new SocialCountsModel(12, 3, 4, null), $post->counts);
    }

    public function testALikeTheAccountAlreadyMadeIsCarried(): void
    {
        $http = $this->answering(['posts' => [[
            'uri' => self::POST_URI,
            'cid' => 'bafy-cid',
            'viewer' => ['like' => self::LIKE_URI],
        ]]]);

        $post = $this->resolver($http)->resolve(SocialApiWiring::blueskyAccount(), self::POST_URI);

        self::assertSame(self::LIKE_URI, $post->existingLikeId);
        self::assertSame('bafy-cid', $post->contentId);
    }

    public function testARepostTheAccountAlreadyMadeIsCarried(): void
    {
        $http = $this->answering(['posts' => [[
            'uri' => self::POST_URI,
            'cid' => 'bafy-cid',
            'viewer' => ['like' => self::LIKE_URI, 'repost' => self::REPOST_URI],
        ]]]);

        $post = $this->resolver($http)->resolve(SocialApiWiring::blueskyAccount(), self::POST_URI);

        self::assertSame(self::REPOST_URI, $post->existingRepostId);
        self::assertSame(self::LIKE_URI, $post->existingLikeId);
        self::assertTrue($post->canRepost);
    }

    public function testALikeUriIsNoRepost(): void
    {
        $http = $this->answering(['posts' => [[
            'uri' => self::POST_URI,
            'cid' => 'bafy-cid',
            'viewer' => ['repost' => self::LIKE_URI],
        ]]]);

        $post = $this->resolver($http)->resolve(SocialApiWiring::blueskyAccount(), self::POST_URI);

        self::assertNull($post->existingRepostId);
    }

    #[DataProvider('malformedLikeIds')]
    public function testAMalformedViewerLikeReadsAsNoLike(string $like): void
    {
        $http = $this->answering(['posts' => [[
            'uri' => self::POST_URI,
            'cid' => 'bafy-cid',
            'viewer' => ['like' => $like],
        ]]]);

        $post = $this->resolver($http)->resolve(SocialApiWiring::blueskyAccount(), self::POST_URI);

        self::assertNull($post->existingLikeId);
        self::assertSame('bafy-cid', $post->contentId);
    }

    /** @return iterable<string, array{string}> */
    public static function malformedLikeIds(): iterable
    {
        yield 'no slash' => ['3lk2'];
        yield 'empty record key' => ['at://did:plc:alice/app.bsky.feed.like/'];
        yield 'over 512 characters' => ['at://did:plc:alice/app.bsky.feed.like/' . str_repeat('a', 480)];
    }

    public function testADidWebViewerLikeIsCarried(): void
    {
        $like = 'at://did:web:example.com/app.bsky.feed.like/3lk2';
        $http = $this->answering(['posts' => [[
            'uri' => self::POST_URI,
            'cid' => 'bafy-cid',
            'viewer' => ['like' => $like],
        ]]]);

        $post = $this->resolver($http)->resolve(SocialApiWiring::blueskyAccount(), self::POST_URI);

        self::assertSame($like, $post->existingLikeId);
    }

    public function testAPostNamedByItsAuthorsHandleIsAskedForAndKeptByTheAuthorsDid(): void
    {
        $http = new RoutedHttpClient([
            self::RESOLVE_HANDLE => self::json(['did' => 'did:plc:bob']),
            self::SESSION => SocialApiWiring::blueskySessionAnswer('jwt-1'),
            self::GET_POSTS => self::json(['posts' => [['uri' => self::POST_URI, 'cid' => 'bafy-cid']]]),
        ]);

        $post = $this->resolver($http)->resolve(SocialApiWiring::blueskyAccount(), self::HANDLE_POST_URI);

        self::assertSame(self::POST_URI, $post->postId);
    }

    public function testAPostNamedByAHandleNoAccountHasIsNotFound(): void
    {
        $http = new RoutedHttpClient([
            self::RESOLVE_HANDLE => self::json(['error' => 'InvalidRequest'], Response::HTTP_BAD_REQUEST),
        ]);

        $this->expectException(SocialPostNotFoundException::class);

        $this->resolver($http)->resolve(SocialApiWiring::blueskyAccount(), self::HANDLE_POST_URI);
    }

    public function testAPostBlueskyNoLongerHasIsNotFound(): void
    {
        $this->expectException(SocialPostNotFoundException::class);

        $this->resolver($this->answering(['posts' => []]))->resolve(SocialApiWiring::blueskyAccount(), self::POST_URI);
    }

    /** @param array<mixed> $post */
    #[DataProvider('postsWithoutAUsableCid')]
    public function testAPostWithoutAUsableCidIsAnUnreachableNetwork(array $post): void
    {
        $this->expectException(SocialNetworkUnreachableException::class);

        $resolver = $this->resolver($this->answering(['posts' => [$post]]));

        $resolver->resolve(SocialApiWiring::blueskyAccount(), self::POST_URI);
    }

    /** @return iterable<string, array{array<mixed>}> */
    public static function postsWithoutAUsableCid(): iterable
    {
        yield 'no cid' => [['uri' => self::POST_URI]];
        yield 'numeric cid' => [['uri' => self::POST_URI, 'cid' => 42]];
        yield 'blank cid' => [['uri' => self::POST_URI, 'cid' => '  ']];
    }

    public function testARefusedSessionIsARefusedCredential(): void
    {
        $http = new RoutedHttpClient([
            self::SESSION => SocialApiWiring::blueskySessionAnswer('jwt-1'),
            self::GET_POSTS => self::json(['error' => 'AuthRequired'], Response::HTTP_UNAUTHORIZED),
        ]);

        $this->expectException(SocialCredentialsRejectedException::class);

        $this->resolver($http)->resolve(SocialApiWiring::blueskyAccount(), self::POST_URI);
    }

    /** @param array<mixed> $body */
    private function answering(array $body): RoutedHttpClient
    {
        return new RoutedHttpClient([
            self::SESSION => SocialApiWiring::blueskySessionAnswer('jwt-1'),
            self::GET_POSTS => self::json($body),
        ]);
    }

    private function resolver(RoutedHttpClient $http): BlueskyPostResolver
    {
        return new BlueskyPostResolver(
            SocialApiWiring::blueskySessions($http->client()),
            SocialApiWiring::blueskyIdentityResolver($http->client()),
        );
    }

    /** @param array<mixed> $body */
    private static function json(array $body, int $status = Response::HTTP_OK): MockResponse
    {
        return new MockResponse(json_encode($body, \JSON_THROW_ON_ERROR), ['http_code' => $status]);
    }
}
