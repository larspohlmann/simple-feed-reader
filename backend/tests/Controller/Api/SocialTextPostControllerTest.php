<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Service\Social\Bluesky\BlueskyPostWriter;
use App\Tests\Support\ApiTestCase;
use App\Tests\Support\RoutesSocialApis;
use App\Tests\Support\SocialApiWiring;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Response;

final class SocialTextPostControllerTest extends ApiTestCase
{
    use RoutesSocialApis;

    private const array ROUTED_HOSTS = ['mastodon.example'];
    private const string INSTANCE = 'GET https://mastodon.example/api/v2/instance';
    private const string OWN_ACCOUNT = 'GET https://mastodon.example/api/v1/accounts/verify_credentials';
    private const string STATUSES = 'POST https://mastodon.example/api/v1/statuses';
    private const string PDS_SESSION = 'POST https://pds.example.test/xrpc/com.atproto.server.createSession';
    private const string PDS_CREATE_RECORD = 'POST https://pds.example.test/xrpc/com.atproto.repo.createRecord';
    private const string COMPOSER = '/api/social/post-composer?network=';
    private const string POSTS = '/api/social/posts';
    private const string POST_ADDRESS = 'https://mastodon.example/users/alice/statuses/303';
    private const string POST_URL = 'https://mastodon.example/@alice/303';

    protected function setUp(): void
    {
        self::bootKernel();
        $rateLimiterCache = self::getContainer()->get('test.cache.rate_limiter');
        self::assertInstanceOf(CacheItemPoolInterface::class, $rateLimiterCache);
        $rateLimiterCache->clear();
        self::ensureKernelShutdown();
    }

    public function testAnAnonymousCallerIsRefused(): void
    {
        $client = static::createClient();

        $client->request('GET', self::COMPOSER . 'mastodon');
        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);

        $client->request('POST', self::POSTS);
        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testThePostComposerStartsEmptyWithNothingAnsweredOrShared(): void
    {
        $client = $this->clientRouting(self::ROUTED_HOSTS, [
            self::INSTANCE => new MockResponse('{"configuration":{"statuses":{"max_characters":1000}}}'),
            self::OWN_ACCOUNT => new MockResponse('{"id":"1","source":{"privacy":"unlisted"}}'),
        ]);
        $user = $this->signIn($client, 'post-composer-mastodon@example.test');
        $account = $this->mastodonAccountOf($user);

        $client->request('GET', self::COMPOSER . 'mastodon');

        self::assertResponseIsSuccessful();
        self::assertSame([
            'accounts' => [['id' => $account->requireId(), 'handle' => '@alice@mastodon.example']],
            'accountId' => $account->requireId(),
            'canSend' => true,
            'maxCharacters' => 1000,
            'visibility' => 'unlisted',
            'lemmy' => null,
            'kind' => 'post',
            'text' => '',
            'subject' => null,
        ], $this->payload($client));
    }

    public function testThePostComposerOnBlueskyStartsFromItsGraphemeLimit(): void
    {
        $client = $this->clientRouting(self::ROUTED_HOSTS, []);
        $user = $this->signIn($client, 'post-composer-bluesky@example.test');
        $this->blueskyAccountOf($user);

        $client->request('GET', self::COMPOSER . 'bluesky');

        self::assertResponseIsSuccessful();
        self::assertSame(BlueskyPostWriter::MAX_GRAPHEMES, $this->payload($client)['maxCharacters']);
        self::assertSame([], $this->http->requests);
    }

    public function testThePostComposerNeedsAKnownNetwork(): void
    {
        $client = $this->clientRouting(self::ROUTED_HOSTS, []);
        $this->signIn($client, 'post-composer-network@example.test');

        $client->request('GET', self::COMPOSER . 'friendica');
        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);

        $client->request('GET', '/api/social/post-composer');
        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
    }

    public function testATextPostIsPublishedOnMastodonWithTheComposersChoicesAndNothingAttached(): void
    {
        $client = $this->clientRouting(self::ROUTED_HOSTS, [
            self::INSTANCE => new MockResponse('{}'),
            self::STATUSES => self::published(),
        ]);
        $user = $this->signIn($client, 'text-post-mastodon@example.test');
        $this->mastodonAccountOf($user);

        $this->send($client, ['network' => 'mastodon', 'text' => 'Just a thought', 'visibility' => 'unlisted']);

        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        self::assertSame(['address' => self::POST_ADDRESS, 'url' => self::POST_URL], $this->payload($client));
        self::assertSame([self::INSTANCE, self::STATUSES], $this->routesAsked());
        self::assertSame(
            ['status' => 'Just a thought', 'visibility' => 'unlisted'],
            json_decode($this->http->requests[1]['body'], true),
        );
    }

    public function testATextPostIsPublishedOnBlueskyWithoutAnEmbedOrAReply(): void
    {
        $client = $this->clientRouting([...self::ROUTED_HOSTS, 'pds.example.test'], [
            self::PDS_SESSION => SocialApiWiring::blueskySessionAnswer('jwt-1'),
            self::PDS_CREATE_RECORD => new MockResponse(
                '{"uri":"at://did:plc:alice/app.bsky.feed.post/3new","cid":"bafy-new"}',
            ),
        ]);
        $user = $this->signIn($client, 'text-post-bluesky@example.test');
        $this->blueskyAccountOf($user);

        $this->send($client, ['network' => 'bluesky', 'text' => 'Just a thought']);

        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $createRecord = array_last($this->http->requests);
        self::assertIsArray($createRecord);
        $created = json_decode($createRecord['body'], true);
        self::assertIsArray($created);
        self::assertIsArray($created['record']);
        self::assertSame('Just a thought', $created['record']['text']);
        self::assertArrayNotHasKey('embed', $created['record']);
        self::assertArrayNotHasKey('reply', $created['record']);
    }

    public function testABlankTextPostIsRejectedBeforeAnyCall(): void
    {
        $client = $this->clientRouting(self::ROUTED_HOSTS, []);
        $user = $this->signIn($client, 'text-post-blank@example.test');
        $this->mastodonAccountOf($user);

        $this->send($client, ['network' => 'mastodon', 'text' => "  \n "]);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertSame([], $this->http->requests);
    }

    public function testATextPostOnLemmyIsRefusedBeforeAnyCall(): void
    {
        $client = $this->clientRouting(['lemmy.example'], []);
        $user = $this->signIn($client, 'text-post-lemmy@example.test');
        $this->lemmyAccountOf($user);

        $client->request('GET', self::COMPOSER . 'lemmy');
        self::assertSame('social_post_refused', $this->payload($client)['type']);

        $this->send($client, ['network' => 'lemmy', 'text' => 'Hi']);
        self::assertSame('social_post_refused', $this->payload($client)['type']);
        self::assertSame([], $this->http->requests);
    }

    public function testATextPostWithoutAnAccountOnTheNetworkIsRefused(): void
    {
        $client = $this->clientRouting(self::ROUTED_HOSTS, []);
        $this->signIn($client, 'text-post-no-account@example.test');

        $this->send($client, ['network' => 'mastodon', 'text' => 'Hi']);

        self::assertSame('social_account_missing', $this->payload($client)['type']);
        self::assertSame([], $this->http->requests);
    }

    /** @param array<string, string> $body */
    private function send(KernelBrowser $client, array $body): void
    {
        $client->request(
            'POST',
            self::POSTS,
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode($body, \JSON_THROW_ON_ERROR),
        );
    }

    private static function published(): MockResponse
    {
        return new MockResponse(json_encode(
            ['id' => '303', 'uri' => self::POST_ADDRESS, 'url' => self::POST_URL],
            \JSON_THROW_ON_ERROR,
        ));
    }
}
