<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Entity\User;
use App\Service\Fetch\Exception\FeedUnreachableException;
use App\Service\Fetch\FeedFetcherInterface;
use App\Service\Fetch\FetchResponse;
use App\Tests\Support\StubFeedFetcher;
use App\Tests\Support\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class FeedPreviewControllerTest extends WebTestCase
{
    private const URL = 'https://example.com/feed.xml';

    protected function setUp(): void
    {
        // Same reasoning as EntryReaderControllerTest: the feed_preview
        // limiter counts in a FILESYSTEM pool that outlives the run, so a
        // prior case's spend must not bleed into this one and trip a 429.
        self::bootKernel();
        $rateLimiterCache = self::getContainer()->get('test.cache.rate_limiter');
        self::assertInstanceOf(CacheItemPoolInterface::class, $rateLimiterCache);
        $rateLimiterCache->clear();
        self::ensureKernelShutdown();
    }

    /** @return array{0: array<string,string>, 1: User} */
    private function auth(string $email): array
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        self::assertInstanceOf(UserPasswordHasherInterface::class, $hasher);
        $user = (new UserFactory($em, $hasher))->create($email);

        $tokens = self::getContainer()->get(JWTTokenManagerInterface::class);
        self::assertInstanceOf(JWTTokenManagerInterface::class, $tokens);

        return [['HTTP_AUTHORIZATION' => 'Bearer ' . $tokens->create($user)], $user];
    }

    /**
     * FeedPreviewService is `final readonly`, so it cannot be subclassed into
     * a mock, and it is wired from a private container definition, so it
     * cannot be swapped directly either. Instead swap its one I/O dependency
     * — FeedFetcherInterface, already exposed public in services_test.yaml
     * for exactly this reason (see EntryReaderControllerTest's analogous use
     * of ArticleExtractorInterface) — and let the real FeedPreviewService and
     * FeedParser run unmodified against the stubbed fetch result.
     */
    private function installFetcher(StubFeedFetcher $fetcher): void
    {
        self::getContainer()->set(FeedFetcherInterface::class, $fetcher);
    }

    private function fetcherWithBody(string $xml): StubFeedFetcher
    {
        $fetcher = new StubFeedFetcher();
        $fetcher->willReturn(
            self::URL,
            FetchResponse::fetched(self::URL, permanentRedirect: false, body: $xml, etag: null, lastModified: null),
        );

        return $fetcher;
    }

    private function feedXml(): string
    {
        return <<<'XML'
            <?xml version="1.0" encoding="UTF-8"?>
            <rss version="2.0"
                 xmlns:content="http://purl.org/rss/1.0/modules/content/"
                 xmlns:dc="http://purl.org/dc/elements/1.1/"
                 xmlns:media="http://search.yahoo.com/mrss/">
              <channel>
                <title>Example Feed</title>
                <link>https://example.com/</link>
                <description>An example feed</description>
                <item>
                  <title>First post</title>
                  <link>https://example.com/1</link>
                  <guid>https://example.com/1</guid>
                  <dc:creator>A. Writer</dc:creator>
                  <pubDate>Wed, 01 Jul 2026 10:00:00 GMT</pubDate>
                  <description>A short teaser for the first post.</description>
                  <media:content url="https://example.com/pic.jpg" medium="image" />
                </item>
              </channel>
            </rss>
            XML;
    }

    public function testAuthenticatedValidUrlReturnsPreviewJson(): void
    {
        $client = self::createClient();
        [$headers] = $this->auth('preview-ok@example.com');
        $this->installFetcher($this->fetcherWithBody($this->feedXml()));

        $client->request(
            'POST',
            '/api/feeds/preview',
            server: $headers + ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['url' => self::URL], \JSON_THROW_ON_ERROR),
        );

        self::assertResponseIsSuccessful();
        $body = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($body);
        self::assertSame('Example Feed', $body['feed']['title']);
        self::assertSame(1, $body['feed']['itemCount']);
        self::assertArrayHasKey('content', $body['feed']);
        self::assertTrue($body['feed']['hasImages']);
        self::assertCount(1, $body['feed']['items']);
        self::assertSame('First post', $body['feed']['items'][0]['title']);
        self::assertSame('A. Writer', $body['feed']['items'][0]['author']);
        self::assertTrue($body['feed']['items'][0]['hasImage']);
        self::assertArrayHasKey('textLength', $body['feed']['items'][0]);
        self::assertArrayHasKey('snippet', $body['feed']['items'][0]);
        self::assertArrayHasKey('publishedAt', $body['feed']['items'][0]);
    }

    public function testFetchFailureReturns422(): void
    {
        $client = self::createClient();
        [$headers] = $this->auth('preview-fail@example.com');
        $fetcher = new StubFeedFetcher();
        $fetcher->willThrow(self::URL, new FeedUnreachableException('blocked'));
        $this->installFetcher($fetcher);

        $client->request(
            'POST',
            '/api/feeds/preview',
            server: $headers + ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['url' => self::URL], \JSON_THROW_ON_ERROR),
        );

        self::assertResponseStatusCodeSame(422);
    }

    public function testAnonymousIsRejected(): void
    {
        $client = self::createClient();

        $client->request(
            'POST',
            '/api/feeds/preview',
            content: json_encode(['url' => self::URL], \JSON_THROW_ON_ERROR),
        );

        self::assertResponseStatusCodeSame(401);
    }
}
