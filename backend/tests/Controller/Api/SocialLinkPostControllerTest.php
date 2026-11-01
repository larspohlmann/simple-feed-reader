<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Entity\LinkCardContent;
use App\Service\Social\PostWriter\BlueskyPostWriter;
use App\Tests\Support\ApiTestCase;
use App\Tests\Support\RoutesSocialApis;
use App\Tests\Support\SocialAccounts;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Response;

final class SocialLinkPostControllerTest extends ApiTestCase
{
    use RoutesSocialApis;

    private const array ROUTED_HOSTS = ['mastodon.example'];
    private const string INSTANCE = 'GET https://mastodon.example/api/v2/instance';
    private const string OWN_ACCOUNT = 'GET https://mastodon.example/api/v1/accounts/verify_credentials';
    private const string STATUSES = 'POST https://mastodon.example/api/v1/statuses';
    private const string LINK_COMPOSER = '/api/social/link-composer?network=';
    private const string LINKS = '/api/social/links?network=';
    private const string ARTICLE_URL = 'https://example.com/article?id=7';
    private const string LINK_POST = 'https://mastodon.example/users/alice/statuses/202';
    private const string IDEMPOTENCY_KEY = '9b2f6c1e-4a7d-4c1b-8f3e-2d5a6b7c8d9e';

    /** Must match framework.rate_limiter.social_writes.limit in rate_limiter.yaml. */
    private const int WRITE_BUDGET = 30;

    /** Must match framework.rate_limiter.social_viewer.limit in rate_limiter.yaml. */
    private const int VIEWER_BUDGET = 120;

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

        $client->request('GET', self::LINK_COMPOSER . 'mastodon');
        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);

        $client->request('POST', self::LINKS . 'mastodon');
        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testTheLinkComposerStartsFromTheMastodonAccountsOwnDefaults(): void
    {
        $client = $this->clientRouting(self::ROUTED_HOSTS, [
            self::INSTANCE => new MockResponse('{"configuration":{"statuses":{"max_characters":1000}}}'),
            self::OWN_ACCOUNT => new MockResponse('{"id":"1","source":{"privacy":"unlisted"}}'),
        ]);
        $user = $this->signIn($client, 'link-composer-mastodon@example.test');
        $account = $this->mastodonAccountOf($user);

        $client->request('GET', self::LINK_COMPOSER . 'mastodon');

        self::assertResponseIsSuccessful();
        self::assertSame([
            'accounts' => [['id' => $account->requireId(), 'handle' => '@alice@mastodon.example']],
            'accountId' => $account->requireId(),
            'canSend' => true,
            'maxCharacters' => 1000,
            'visibility' => 'unlisted',
            'mentions' => [],
        ], $this->payload($client));
    }

    public function testTheLinkComposerOnBlueskyStartsFromItsGraphemeLimit(): void
    {
        $client = $this->clientRouting(self::ROUTED_HOSTS, []);
        $user = $this->signIn($client, 'link-composer-bluesky@example.test');
        $account = SocialAccounts::blueskyOf($user);
        $this->entityManager()->persist($account);
        $this->entityManager()->flush();

        $client->request('GET', self::LINK_COMPOSER . 'bluesky');

        self::assertResponseIsSuccessful();
        self::assertSame([
            'accounts' => [['id' => $account->requireId(), 'handle' => $account->getHandle()]],
            'accountId' => $account->requireId(),
            'canSend' => true,
            'maxCharacters' => BlueskyPostWriter::MAX_GRAPHEMES,
            'visibility' => null,
            'mentions' => [],
        ], $this->payload($client));
        self::assertSame([], $this->http->requests);
    }

    #[DataProvider('missingNetworks')]
    public function testTheLinkComposerAndALinkPostNeedAKnownNetwork(string $query): void
    {
        $client = $this->clientRouting(self::ROUTED_HOSTS, []);
        $user = $this->signIn($client, sprintf('link-network-%s@example.test', md5($query)));
        $this->mastodonAccountOf($user);

        $client->request('GET', '/api/social/link-composer' . $query);
        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);

        $this->send($client, '/api/social/links' . $query, ['url' => self::ARTICLE_URL, 'text' => 'Hi']);
        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
        self::assertSame([], $this->http->requests);
    }

    /** @return iterable<string, array{string}> */
    public static function missingNetworks(): iterable
    {
        yield 'no network' => [''];
        yield 'unknown network' => ['?network=friendica'];
    }

    public function testALinkPostIsPublishedOnMastodonWithTheComposersChoices(): void
    {
        $client = $this->clientRouting(self::ROUTED_HOSTS, [
            self::STATUSES => new MockResponse(json_encode(
                ['id' => '202', 'uri' => self::LINK_POST, 'url' => 'https://mastodon.example/@alice/202'],
                \JSON_THROW_ON_ERROR,
            )),
        ]);
        $user = $this->signIn($client, 'link-post@example.test');
        $this->mastodonAccountOf($user);

        $this->send($client, self::LINKS . 'mastodon', [
            'url' => self::ARTICLE_URL,
            'text' => 'Worth reading ' . self::ARTICLE_URL,
            'visibility' => 'unlisted',
            'idempotencyKey' => self::IDEMPOTENCY_KEY,
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        self::assertSame(
            ['address' => self::LINK_POST, 'url' => 'https://mastodon.example/@alice/202'],
            $this->payload($client),
        );
        self::assertSame([self::STATUSES], $this->routesAsked());
        self::assertSame(
            ['status' => 'Worth reading ' . self::ARTICLE_URL, 'visibility' => 'unlisted'],
            json_decode($this->http->requests[0]['body'], true),
        );
        self::assertContains('Idempotency-Key: ' . self::IDEMPOTENCY_KEY, $this->http->requests[0]['headers']);
    }

    /** @param array<string, string> $body */
    #[DataProvider('invalidLinkPosts')]
    public function testALinkPostWithoutASharableUrlIsRejectedBeforeAnyCall(array $body): void
    {
        $client = $this->clientRouting(self::ROUTED_HOSTS, []);
        $user = $this->signIn($client, 'link-invalid-' . md5((string) json_encode($body)) . '@example.test');
        $this->mastodonAccountOf($user);

        $this->send($client, self::LINKS . 'mastodon', $body);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertSame([], $this->http->requests);
    }

    /** @return iterable<string, array{array<string, string>}> */
    public static function invalidLinkPosts(): iterable
    {
        yield 'no url' => [['text' => 'Hi']];
        yield 'a blank url' => [['url' => '', 'text' => 'Hi']];
        yield 'no url at all' => [['url' => 'not a link', 'text' => 'Hi']];
        yield 'no http url' => [['url' => 'ftp://example.com/file', 'text' => 'Hi']];
        yield 'a host without tld' => [['url' => 'https://intranet/page', 'text' => 'Hi']];
        yield 'a too long url' => [[
            'url' => 'https://example.com/' . str_repeat('a', LinkCardContent::URL_MAX),
            'text' => 'Hi',
        ]];
        yield 'blank text' => [['url' => self::ARTICLE_URL, 'text' => '   ']];
    }

    public function testALinkPostDrawsOnTheWriteBudget(): void
    {
        $client = $this->clientRouting(self::ROUTED_HOSTS, []);
        $this->signIn($client, 'link-write-budget@example.test');
        for ($attempt = 0; $attempt < self::WRITE_BUDGET; ++$attempt) {
            $this->send($client, self::LINKS . 'mastodon', ['url' => self::ARTICLE_URL, 'text' => 'Hi']);
        }

        $this->send($client, self::LINKS . 'mastodon', ['url' => self::ARTICLE_URL, 'text' => 'Hi']);

        self::assertResponseStatusCodeSame(Response::HTTP_TOO_MANY_REQUESTS);
    }

    public function testOpeningTheLinkComposerDrawsOnTheViewerBudget(): void
    {
        $client = $this->clientRouting(self::ROUTED_HOSTS, []);
        $this->signIn($client, 'link-viewer-budget@example.test');
        for ($attempt = 0; $attempt < self::VIEWER_BUDGET; ++$attempt) {
            $client->request('GET', self::LINK_COMPOSER . 'mastodon');
        }

        $client->request('GET', self::LINK_COMPOSER . 'mastodon');

        self::assertResponseStatusCodeSame(Response::HTTP_TOO_MANY_REQUESTS);
    }

    /** @param array<string, string> $body */
    private function send(KernelBrowser $client, string $path, array $body): void
    {
        $client->request(
            'POST',
            $path,
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode($body, \JSON_THROW_ON_ERROR),
        );
    }
}
