<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Entity\Feed;
use App\Entity\Subscription;
use App\Service\Fetch\Exception\FeedUnreachableException;
use App\Service\Fetch\FeedFetcherInterface;
use App\Service\Fetch\FetchResponse;
use App\Service\Subscription\SubscriptionService;
use App\Tests\Service\Scraper\ScrapedFixtures;
use App\Tests\Support\StubFeedFetcher;
use App\Tests\Support\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class SubscriptionControllerTest extends WebTestCase
{
    use ScrapedFixtures;

    private function userFactory(): UserFactory
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        self::assertInstanceOf(UserPasswordHasherInterface::class, $hasher);

        return new UserFactory($em, $hasher);
    }

    /** @return array<string, string> */
    private function authHeader(string $email): array
    {
        $user = $this->userFactory()->create($email);

        $tokens = self::getContainer()->get(JWTTokenManagerInterface::class);
        self::assertInstanceOf(JWTTokenManagerInterface::class, $tokens);

        return ['HTTP_AUTHORIZATION' => 'Bearer ' . $tokens->create($user)];
    }

    /**
     * Drive the REAL FeedDiscovery by swapping the SSRF-guarded fetcher for a
     * stub — the established seam in this codebase (FeedFetcherInterface is made
     * public in config/services_test.yaml). FeedDiscovery is `final` and cannot
     * be stubbed directly. Must be called BEFORE the request that triggers a
     * fetch, while the kernel is still on its first (un-rebooted) boot.
     */
    private function installFetcher(StubFeedFetcher $stub): void
    {
        self::getContainer()->set(FeedFetcherInterface::class, $stub);
    }

    public function testAnonymousIsRejected(): void
    {
        $client = self::createClient();
        $client->request('GET', '/api/subscriptions');
        self::assertResponseStatusCodeSame(401);
    }

    public function testSubscribeToDirectFeedThenList(): void
    {
        $client = self::createClient();
        $headers = $this->authHeader('reader@example.com');

        $rss = file_get_contents(__DIR__ . '/../../Fixtures/feeds/rss2-basic.xml');
        self::assertIsString($rss);

        $stub = new StubFeedFetcher();
        // FeedDiscovery reports the response's finalUrl as the canonical feed
        // URL, so the created subscription's feedUrl is the finalUrl, not the
        // address the user typed.
        $stub->willReturn(
            'https://example.com/feed',
            FetchResponse::fetched(
                'https://example.com/feed.xml',
                permanentRedirect: false,
                body: $rss,
                etag: null,
                lastModified: null,
            ),
        );
        $this->installFetcher($stub);

        $client->request(
            'POST',
            '/api/subscriptions',
            server: $headers + ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['url' => 'https://example.com/feed'], \JSON_THROW_ON_ERROR),
        );
        self::assertResponseStatusCodeSame(201);
        $created = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($created);
        self::assertIsArray($created['subscription']);
        self::assertSame('https://example.com/feed.xml', $created['subscription']['feedUrl']);
        // A discovery-confirmed feed document parses as XML — the refresh
        // pipeline must never route it through the HTML scraper.
        self::assertSame('xml', $created['subscription']['sourceFormat']);

        $client->request('GET', '/api/subscriptions', server: $headers);
        self::assertResponseIsSuccessful();
        $list = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($list);
        self::assertIsArray($list['subscriptions']);
        self::assertCount(1, $list['subscriptions']);
        $first = $list['subscriptions'][0];
        self::assertIsArray($first);
        self::assertSame('https://example.com/feed.xml', $first['feedUrl']);
        self::assertArrayHasKey('unreadCount', $first);
        // Subscribe defers ingestion to the refresh pipeline, so a freshly
        // subscribed feed carries no entries yet — deterministically 0 unread.
        self::assertSame(0, $first['unreadCount']);
    }

    public function testSubscribeToHtmlReturnsCandidates(): void
    {
        $client = self::createClient();
        $headers = $this->authHeader('html@example.com');

        $html = '<!doctype html><html><head>'
            . '<link rel="alternate" type="application/rss+xml" href="/rss.xml">'
            . '</head><body>x</body></html>';

        $stub = new StubFeedFetcher();
        // finalUrl carries a trailing slash so the relative /rss.xml resolves to
        // the site root, not to a nested path.
        $stub->willReturn(
            'https://example.com/blog',
            FetchResponse::fetched(
                'https://example.com/blog/',
                permanentRedirect: false,
                body: $html,
                etag: null,
                lastModified: null,
            ),
        );
        $this->installFetcher($stub);

        $client->request(
            'POST',
            '/api/subscriptions',
            server: $headers + ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['url' => 'https://example.com/blog'], \JSON_THROW_ON_ERROR),
        );
        self::assertResponseStatusCodeSame(200);
        $body = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($body);
        self::assertIsArray($body['candidates']);
        $candidate = $body['candidates'][0];
        self::assertIsArray($candidate);
        self::assertSame('https://example.com/rss.xml', $candidate['url']);
        self::assertSame('rss', $candidate['format']);
        // The reason key appears only when discovery actually failed.
        self::assertArrayNotHasKey('scrapeFailureReason', $body);
    }

    public function testSubscribeToFeedlessPageOffersScrapedCandidate(): void
    {
        $client = self::createClient();
        $headers = $this->authHeader('feedless@example.com');

        $stub = new StubFeedFetcher();
        $stub->willReturn(
            'https://www.heise.de',
            FetchResponse::fetched(
                'https://www.heise.de/',
                permanentRedirect: false,
                body: $this->scrapedFixture('heise-2026-07-23.html'),
                etag: null,
                lastModified: null,
            ),
        );
        $this->installFetcher($stub);

        $client->request(
            'POST',
            '/api/subscriptions',
            server: $headers + ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['url' => 'https://www.heise.de'], \JSON_THROW_ON_ERROR),
        );
        self::assertResponseStatusCodeSame(200);
        $body = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($body);
        self::assertIsArray($body['candidates']);
        self::assertCount(1, $body['candidates']);
        $candidate = $body['candidates'][0];
        self::assertIsArray($candidate);
        self::assertSame('https://www.heise.de/', $candidate['url']);
        self::assertSame('scraped', $candidate['format']);
        self::assertArrayNotHasKey('scrapeFailureReason', $body);
    }

    public function testBlockedSiteReportsReasonWithEmptyCandidates(): void
    {
        $client = self::createClient();
        $headers = $this->authHeader('blocked@example.com');

        $stub = new StubFeedFetcher();
        $stub->willThrow('https://forbidden.example.com', new FeedUnreachableException('x: HTTP 403', 403));
        $this->installFetcher($stub);

        $client->request(
            'POST',
            '/api/subscriptions',
            server: $headers + ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['url' => 'https://forbidden.example.com'], \JSON_THROW_ON_ERROR),
        );
        // Still a 200: "this site refused us" is an expected outcome the
        // subscribe dialog renders, not an API error.
        self::assertResponseStatusCodeSame(200);
        $body = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame(['candidates' => [], 'scrapeFailureReason' => 'blocked'], $body);
    }

    public function testScrapedFormatSubscribeSkipsDiscoveryAndMarksTheFeed(): void
    {
        $client = self::createClient();
        $headers = $this->authHeader('scraper@example.com');

        // No stubbed URLs at all: the scraped-format path re-posts a candidate
        // URL discovery itself just produced, so ANY fetch here is a bug and
        // fails loudly inside the stub.
        $stub = new StubFeedFetcher();
        $this->installFetcher($stub);

        $client->request(
            'POST',
            '/api/subscriptions',
            server: $headers + ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['url' => 'https://www.heise.de/', 'format' => 'scraped'], \JSON_THROW_ON_ERROR),
        );
        self::assertResponseStatusCodeSame(201);
        $created = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($created);
        self::assertIsArray($created['subscription']);
        self::assertSame('https://www.heise.de/', $created['subscription']['feedUrl']);
        self::assertSame('scraped', $created['subscription']['sourceFormat']);
        self::assertSame([], $stub->fetchedUrls);

        // The FORMAT must be persisted on the shared feed row — it is what the
        // refresh pipeline later dispatches on, not the response JSON.
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $feed = $em->getRepository(Feed::class)->findOneBy(['url' => 'https://www.heise.de/']);
        self::assertInstanceOf(Feed::class, $feed);
        self::assertSame('scraped', $feed->getSourceFormat());
    }

    public function testScrapedFormatSubscribeStillEnforcesTheSubscriptionCap(): void
    {
        $client = self::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        $user = $this->userFactory()->create('atcap@example.com');
        $when = new \DateTimeImmutable('2026-07-01T00:00:00Z');
        for ($i = 0; $i < SubscriptionService::MAX_SUBSCRIPTIONS_PER_USER; $i++) {
            $feed = new Feed(sprintf('https://seed%d.example.com/feed.xml', $i));
            $em->persist($feed);
            $em->persist(new Subscription($user, $feed, $when));
        }
        $em->flush();

        // Nothing stubbed: the scraped path never fetches, and if a regression
        // makes it fetch, the stub fails the test instead of hitting the net.
        $this->installFetcher(new StubFeedFetcher());

        $tokens = self::getContainer()->get(JWTTokenManagerInterface::class);
        self::assertInstanceOf(JWTTokenManagerInterface::class, $tokens);
        $headers = ['HTTP_AUTHORIZATION' => 'Bearer ' . $tokens->create($user)];

        $client->request(
            'POST',
            '/api/subscriptions',
            server: $headers + ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['url' => 'https://www.heise.de/', 'format' => 'scraped'], \JSON_THROW_ON_ERROR),
        );
        // Exactly the failure the discovery-backed path answers at the cap —
        // the scraped shortcut must not become a cap bypass.
        self::assertResponseStatusCodeSame(409);
        $body = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($body);
        self::assertSame('subscription_limit_reached', $body['type']);
    }

    public function testCannotUpdateAnotherUsersSubscription(): void
    {
        $client = self::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        $factory = $this->userFactory();
        $stranger = $factory->create('stranger@example.com');
        $feed = new Feed('https://example.com/x.xml');
        $em->persist($feed);
        $sub = new Subscription($stranger, $feed, new \DateTimeImmutable('2026-01-01T00:00:00Z'));
        $em->persist($sub);
        $em->flush();

        $tokens = self::getContainer()->get(JWTTokenManagerInterface::class);
        self::assertInstanceOf(JWTTokenManagerInterface::class, $tokens);
        $attacker = $factory->create('attacker@example.com');
        $headers = ['HTTP_AUTHORIZATION' => 'Bearer ' . $tokens->create($attacker)];

        $client->request(
            'PATCH',
            '/api/subscriptions/' . $sub->getId(),
            server: $headers + ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['customTitle' => 'hijacked', 'tagIds' => []], \JSON_THROW_ON_ERROR),
        );
        self::assertResponseStatusCodeSame(404); // not 403 — do not reveal existence
    }
}
