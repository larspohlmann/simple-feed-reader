<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\PostResolver;

use App\Service\Social\Exception\SocialCredentialsRejectedException;
use App\Service\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\Exception\SocialPostNotFoundException;
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
    private const string SESSION = 'POST https://pds.example.test/xrpc/com.atproto.server.createSession';
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
        return new BlueskyPostResolver(SocialApiWiring::blueskySessions($http->client()));
    }

    /** @param array<mixed> $body */
    private static function json(array $body, int $status = Response::HTTP_OK): MockResponse
    {
        return new MockResponse(json_encode($body, \JSON_THROW_ON_ERROR), ['http_code' => $status]);
    }
}
