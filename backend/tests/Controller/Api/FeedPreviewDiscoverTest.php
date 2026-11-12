<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Entity\Subscription;
use App\Entity\User;
use App\Service\Fetch\BatchFeedFetcher\BatchFeedFetcherInterface;
use App\Service\Fetch\Exception\FeedThrottledException;
use App\Service\Fetch\Exception\FeedUnreachableException;
use App\Service\Fetch\FeedFetcher\FeedFetcherInterface;
use App\Service\Fetch\Model\FetchResponseModel;
use App\Service\Social\Model\HiddenProfileModel;
use App\Tests\Service\Scraper\ScrapedFixtures;
use App\Tests\Support\ApiTestCase;
use App\Tests\Support\BlueskyProfiles;
use App\Tests\Support\SocialAccounts;
use App\Tests\Support\StoredUserSettings;
use App\Tests\Support\StubFeedFetcher;
use App\Tests\Support\StubHiddenProfiles;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpFoundation\Response;

final class FeedPreviewDiscoverTest extends ApiTestCase
{
    use ScrapedFixtures;

    private const int DISCOVER_BUDGET = 60;

    protected function setUp(): void
    {
        // The feed_discover limiter counts in a FILESYSTEM pool that outlives the run, as feed_preview's does.
        self::bootKernel();
        $rateLimiterCache = self::getContainer()->get('test.cache.rate_limiter');
        self::assertInstanceOf(CacheItemPoolInterface::class, $rateLimiterCache);
        $rateLimiterCache->clear();
        self::ensureKernelShutdown();
    }

    /** The real FeedDiscovery runs; only its fetcher is a stub. Call before the request, on the kernel's first boot. */
    private function installFetcher(StubFeedFetcher $stub): void
    {
        $stub->willThrowForEverythingElse(new FeedUnreachableException('x: HTTP 404', 404));
        self::getContainer()->set(FeedFetcherInterface::class, $stub);
        self::getContainer()->set(BatchFeedFetcherInterface::class, $stub);
    }

    private function serving(string $url, string $finalUrl, string $body): StubFeedFetcher
    {
        $stub = new StubFeedFetcher();
        $stub->willReturn(
            $url,
            FetchResponseModel::fetched(
                $finalUrl,
                permanentRedirect: false,
                body: $body,
                etag: null,
                lastModified: null,
            ),
        );

        return $stub;
    }

    private function rss(): string
    {
        $rss = file_get_contents(__DIR__ . '/../../Fixtures/feeds/rss2-basic.xml');
        self::assertIsString($rss);

        return $rss;
    }

    /** @return array<string, mixed> */
    private function discover(KernelBrowser $client, User $user, string $url): array
    {
        $client->request(
            'POST',
            '/api/feeds/discover',
            server: $this->jsonBearerFor($user),
            content: json_encode(['url' => $url], \JSON_THROW_ON_ERROR),
        );

        return $this->payload($client);
    }

    public function testAnonymousIsRejected(): void
    {
        $client = self::createClient();
        $client->request(
            'POST',
            '/api/feeds/discover',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['url' => 'https://example.com'], \JSON_THROW_ON_ERROR),
        );

        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testADirectFeedComesBackAsOneCandidateCarryingItsPreviewAndNothingIsSubscribed(): void
    {
        $client = self::createClient();
        $user = $this->factory()->create('discover-direct@example.com');
        $this->installFetcher($this->serving('https://example.com/feed', 'https://example.com/feed.xml', $this->rss()));

        $body = $this->discover($client, $user, 'https://example.com/feed');

        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        self::assertArrayNotHasKey('scrapeFailureReason', $body);
        $candidates = $body['candidates'] ?? null;
        self::assertIsArray($candidates);
        self::assertCount(1, $candidates);
        $candidate = $candidates[0];
        self::assertIsArray($candidate);
        self::assertSame('https://example.com/feed.xml', $candidate['url']);
        self::assertSame('Example Tech Blog', $candidate['title']);
        self::assertSame('rss', $candidate['format']);
        self::assertArrayNotHasKey('noPreviewReason', $candidate);
        $preview = $candidate['preview'] ?? null;
        self::assertIsArray($preview);
        self::assertSame('Example Tech Blog', $preview['title']);
        self::assertSame(2, $preview['itemCount']);
        self::assertIsArray($preview['items']);
        self::assertCount(2, $preview['items']);
        self::assertSame(0, $this->entityManager()->getRepository(Subscription::class)->count([]));
    }

    public function testABareHostIsLookedUpOverHttps(): void
    {
        $client = self::createClient();
        $user = $this->factory()->create('discover-bare@example.com');
        $this->installFetcher($this->serving('https://example.com/feed', 'https://example.com/feed', $this->rss()));

        $body = $this->discover($client, $user, 'example.com/feed');

        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        $candidates = $body['candidates'] ?? null;
        self::assertIsArray($candidates);
        self::assertIsArray($candidates[0]);
        self::assertSame('https://example.com/feed', $candidates[0]['url']);
    }

    public function testAPageOffersItsAdvertisedFeedsWithoutPreviews(): void
    {
        $client = self::createClient();
        $user = $this->factory()->create('discover-page@example.com');
        $html = /** @lang TEXT */ '<!doctype html><html><head>'
            . '<link rel="alternate" type="application/rss+xml" href="/rss.xml">'
            . '</head><body>x</body></html>';
        // The trailing slash makes the relative /rss.xml resolve to the site root.
        $this->installFetcher($this->serving('https://example.com/blog', 'https://example.com/blog/', $html));

        $body = $this->discover($client, $user, 'https://example.com/blog');

        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        $candidates = $body['candidates'] ?? null;
        self::assertIsArray($candidates);
        $candidate = $candidates[0];
        self::assertIsArray($candidate);
        self::assertSame('https://example.com/rss.xml', $candidate['url']);
        self::assertSame('rss', $candidate['format']);
        self::assertArrayNotHasKey('preview', $candidate);
        self::assertArrayNotHasKey('scrapeFailureReason', $body);
    }

    public function testAFeedlessPageOffersAScrapedCandidate(): void
    {
        $client = self::createClient();
        $user = $this->factory()->create('discover-feedless@example.com');
        StoredUserSettings::reading($this->entityManager(), $user)->setScrapeFallbackEnabled(true);
        $this->entityManager()->flush();
        $this->installFetcher(
            $this->serving(
                'https://www.heise.de',
                'https://www.heise.de/',
                $this->scrapedFixture('heise-2026-07-23.html'),
            ),
        );

        $body = $this->discover($client, $user, 'https://www.heise.de');

        self::assertSame(
            ['candidates' => [
                ['url' => 'https://www.heise.de/', 'title' => 'heise online', 'format' => 'scraped'],
            ]],
            $body,
        );
    }

    /** With the preference at its default, off, a wiring that never consults ScrapeFallbackPolicy would scrape. */
    public function testAFeedlessPageOffersNoScrapedCandidateWhenTheFallbackIsDisabled(): void
    {
        $client = self::createClient();
        $user = $this->factory()->create('discover-feedless-off@example.com');
        $this->installFetcher(
            $this->serving(
                'https://www.heise.de',
                'https://www.heise.de/',
                $this->scrapedFixture('heise-2026-07-23.html'),
            ),
        );

        self::assertSame(['candidates' => []], $this->discover($client, $user, 'https://www.heise.de'));
    }

    public function testABlockedSiteReportsItsReason(): void
    {
        $client = self::createClient();
        $user = $this->factory()->create('discover-blocked@example.com');
        $stub = new StubFeedFetcher();
        $stub->willThrow('https://forbidden.example.com', new FeedUnreachableException('x: HTTP 403', 403));
        $this->installFetcher($stub);

        $body = $this->discover($client, $user, 'https://forbidden.example.com');

        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        self::assertSame(['candidates' => [], 'scrapeFailureReason' => 'blocked'], $body);
    }

    public function testAThrottledSiteReportsItsReason(): void
    {
        $client = self::createClient();
        $user = $this->factory()->create('discover-throttled@example.com');
        $stub = new StubFeedFetcher();
        $stub->willThrow('https://slow.example.com', new FeedThrottledException('x: HTTP 429', 60));
        $this->installFetcher($stub);

        $body = $this->discover($client, $user, 'https://slow.example.com');

        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        self::assertSame(['candidates' => [], 'scrapeFailureReason' => 'throttled'], $body);
    }

    public function testDiscoverDrawsOnItsOwnPerUserBudget(): void
    {
        $client = self::createClient();
        $client->disableReboot();
        $user = $this->factory()->create('discover-budget@example.com');
        $this->installFetcher($this->serving('https://example.com/feed', 'https://example.com/feed', $this->rss()));
        for ($attempt = 0; $attempt < self::DISCOVER_BUDGET; ++$attempt) {
            $this->discover($client, $user, 'https://example.com/feed');
            self::assertResponseIsSuccessful();
        }

        $client->request(
            'POST',
            '/api/feeds/discover',
            server: $this->jsonBearerFor($user),
            content: json_encode(['url' => 'https://example.com/feed'], \JSON_THROW_ON_ERROR),
        );

        $this->assertRejected($client, Response::HTTP_TOO_MANY_REQUESTS);
    }

    private function hideRusty(): void
    {
        $hiddenProfiles = self::getContainer()->get(StubHiddenProfiles::class);
        self::assertInstanceOf(StubHiddenProfiles::class, $hiddenProfiles);
        $hiddenProfiles->hide(new HiddenProfileModel(BlueskyProfiles::RUSTY, 'Rusty'), 'rusty-17.bsky.social');
    }

    public function testAHiddenBlueskyProfileTheAccountCanReadIsOfferedWithoutAPreviewAndSaysWhy(): void
    {
        $client = self::createClient();
        $this->hideRusty();
        $user = $this->factory()->create('discover-hidden@example.test');
        $this->entityManager()->persist(SocialAccounts::blueskyOf($user));
        $this->entityManager()->flush();
        $this->installFetcher(new StubFeedFetcher());

        $body = $this->discover($client, $user, 'https://bsky.app/profile/rusty-17.bsky.social');

        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        self::assertSame(
            ['candidates' => [[
                'url' => 'https://bsky.app/profile/rusty-17.bsky.social',
                'title' => 'Rusty',
                'format' => 'bluesky-api',
                'noPreviewReason' => 'hidden_profile',
            ]]],
            $body,
        );
    }

    public function testWithoutAConnectedBlueskyAccountTheHiddenProfileSaysToConnectOne(): void
    {
        $client = self::createClient();
        $this->hideRusty();
        $user = $this->factory()->create('discover-hidden-no-account@example.test');
        $this->installFetcher(new StubFeedFetcher());

        $body = $this->discover($client, $user, 'https://bsky.app/profile/rusty-17.bsky.social');

        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        $candidates = $body['candidates'] ?? null;
        self::assertIsArray($candidates);
        self::assertIsArray($candidates[0]);
        self::assertSame('connect_bluesky_account', $candidates[0]['noPreviewReason']);
    }
}
