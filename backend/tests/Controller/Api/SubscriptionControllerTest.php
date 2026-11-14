<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Entity\Feed;
use App\Entity\InclusionChange;
use App\Entity\Subscription;
use App\Entity\Tag;
use App\Entity\User;
use App\Service\Outbound\Fetch\BatchFeedFetcher\BatchFeedFetcherInterface;
use App\Service\Outbound\Fetch\Exception\FeedThrottledException;
use App\Service\Outbound\Fetch\Exception\FeedUnreachableException;
use App\Service\Outbound\Fetch\FeedFetcher\FeedFetcherInterface;
use App\Service\Outbound\Fetch\Model\FetchResponseModel;
use App\Service\Library\Subscription\SubscriptionService;
use App\Tests\Support\ApiTestCase;
use App\Tests\Support\StoredUserSettings;
use App\Tests\Support\StubFeedFetcher;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Response;

final class SubscriptionControllerTest extends ApiTestCase
{
    /**
     * Swaps the SSRF-guarded fetcher for a stub, so the real FeedDiscovery runs (it is final and cannot be stubbed).
     * Call it before the request that fetches, while the kernel is still on its first boot.
     */
    private function installFetcher(StubFeedFetcher $stub): void
    {
        // Discovery guesses feed addresses under any page that names none, so a
        // test cannot list every URL it will ask for without re-deriving the
        // code under test. It says "nothing else is out there" once instead.
        $stub->willThrowForEverythingElse(new FeedUnreachableException('x: HTTP 404', 404));
        self::getContainer()->set(FeedFetcherInterface::class, $stub);
        self::getContainer()->set(BatchFeedFetcherInterface::class, $stub);
    }

    /**
     * The scrape fallback defaults to OFF for every new account; only the test
     * that specifically exercises the scraped-candidate outcome needs it ON.
     */
    private function enableScrapeFallback(string $email): void
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $user = $entityManager->getRepository(User::class)->findOneBy(['email' => User::normalizeEmail($email)]);
        self::assertInstanceOf(User::class, $user);
        StoredUserSettings::reading($entityManager, $user)->setScrapeFallbackEnabled(true);
        $entityManager->flush();
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
        $headers = $this->bearerFor($this->factory()->create('reader@example.com'));

        $rss = file_get_contents(__DIR__ . '/../../Fixtures/feeds/rss2-basic.xml');
        self::assertIsString($rss);

        $stub = new StubFeedFetcher();
        // FeedDiscovery reports the response's finalUrl as the canonical feed
        // URL, so the created subscription's feedUrl is the finalUrl, not the
        // address the user typed.
        $stub->willReturn(
            'https://example.com/feed',
            FetchResponseModel::fetched(
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
        // Discovery read the document to confirm it was a feed, so the entries
        // are already there when the dialog closes.
        self::assertSame(2, $created['subscription']['unreadCount']);
        self::assertSame('Example Tech Blog', $created['subscription']['title']);
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
        // Discovery had to read the document to know it was a feed, and the
        // subscribe stores it — so the feed arrives with the fixture's two
        // entries rather than empty until some later refresh.
        self::assertSame(2, $first['unreadCount']);
        self::assertSame(2, $first['entryCount']);
        // Sidebar favourite/kept badge totals travel on the same payload.
        self::assertSame(0, $list['favoritesCount']);
        self::assertSame(0, $list['keptCount']);
        self::assertIsArray($list['unreadTotals']);
        self::assertSame(2, $list['unreadTotals']['allItems']);
        self::assertSame(0, $list['unreadTotals']['forYou']);
    }

    public function testCountsReturnsUnreadPerFeedAndSurfaceTotals(): void
    {
        $client = self::createClient();
        $headers = $this->bearerFor($this->factory()->create('counter@example.com'));

        $rss = file_get_contents(__DIR__ . '/../../Fixtures/feeds/rss2-basic.xml');
        self::assertIsString($rss);
        $stub = new StubFeedFetcher();
        $stub->willReturn(
            'https://example.com/feed',
            FetchResponseModel::fetched(
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
        $subscriptionId = $created['subscription']['id'];

        // The literal `/counts` must not be swallowed by `/{id}` (numeric only).
        $client->request('GET', '/api/subscriptions/counts', server: $headers);
        self::assertResponseIsSuccessful();

        $counts = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($counts);
        self::assertSame([['id' => $subscriptionId, 'unreadCount' => 2, 'entryCount' => 2]], $counts['subscriptions']);
        self::assertSame(0, $counts['favoritesCount']);
        self::assertSame(0, $counts['keptCount']);
        self::assertSame(0, $counts['viewedCount']);
        self::assertIsArray($counts['unreadTotals']);
        self::assertSame(2, $counts['unreadTotals']['allItems']);
        self::assertSame(0, $counts['unreadTotals']['forYou']);
    }

    public function testCountsRejectsAnonymous(): void
    {
        $client = self::createClient();

        $client->request('GET', '/api/subscriptions/counts');

        self::assertResponseStatusCodeSame(401);
    }

    /**
     * A feed with zero items leaves no row in SubscriptionEntryCountRepository::forUser()'s grouped
     * query, so the list and counts endpoints fall back to a default — proving
     * that default is 0, not a stray increment or decrement of it.
     */
    public function testAFeedWithNoEntriesReportsZeroCounts(): void
    {
        $client = self::createClient();
        $headers = $this->bearerFor($this->factory()->create('empty-feed@example.com'));

        $stub = new StubFeedFetcher();
        $stub->willReturn(
            'https://example.com/feed',
            FetchResponseModel::fetched(
                'https://example.com/feed.xml',
                permanentRedirect: false,
                body: <<<'XML'
                    <?xml version="1.0" encoding="UTF-8"?>
                    <rss version="2.0">
                      <channel>
                        <title>Empty Feed</title>
                        <link>https://blog.example.com/</link>
                        <description>No posts yet</description>
                      </channel>
                    </rss>
                    XML,
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

        $client->request('GET', '/api/subscriptions', server: $headers);
        self::assertResponseIsSuccessful();
        $list = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($list);
        self::assertIsArray($list['subscriptions']);
        $first = $list['subscriptions'][0];
        self::assertIsArray($first);
        self::assertSame(0, $first['unreadCount']);
        self::assertSame(0, $first['entryCount']);
    }

    public function testSubscribeWithTagIdsCreatesAlreadyTaggedFeed(): void
    {
        $client = self::createClient();
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        $user = $this->factory()->create('withtags@example.com');
        $news = new Tag($user, 'News');
        $tech = new Tag($user, 'Tech');
        $entityManager->persist($news);
        $entityManager->persist($tech);
        $entityManager->flush();
        $newsId = $news->requireId();
        $techId = $tech->requireId();

        $rss = file_get_contents(__DIR__ . '/../../Fixtures/feeds/rss2-basic.xml');
        self::assertIsString($rss);
        $stub = new StubFeedFetcher();
        $stub->willReturn(
            'https://example.com/feed',
            FetchResponseModel::fetched(
                'https://example.com/feed.xml',
                permanentRedirect: false,
                body: $rss,
                etag: null,
                lastModified: null,
            ),
        );
        $this->installFetcher($stub);

        $headers = $this->bearerFor($user);

        $client->request(
            'POST',
            '/api/subscriptions',
            server: $headers + ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(
                ['url' => 'https://example.com/feed', 'tagIds' => [$newsId, $techId]],
                \JSON_THROW_ON_ERROR,
            ),
        );
        self::assertResponseStatusCodeSame(201);
        $created = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($created);
        self::assertIsArray($created['subscription']);
        $tags = $created['subscription']['tags'];
        self::assertIsArray($tags);
        self::assertCount(2, $tags);
        self::assertIsArray($tags[0]);
        self::assertIsArray($tags[1]);
        self::assertSame('News', $tags[0]['name']);
        self::assertSame('Tech', $tags[1]['name']);
    }

    /**
     * A tag id the requester does not own is silently dropped, not rejected —
     * the same forgiving contract as the PATCH tag-sync. The feed is still
     * created; it just carries only the caller's own tags.
     */
    public function testSubscribeIgnoresTagIdsOwnedByAnotherUser(): void
    {
        $client = self::createClient();
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        $factory = $this->factory();
        $stranger = $factory->create('tagstranger@example.com');
        $strangerTag = new Tag($stranger, 'Secret');
        $entityManager->persist($strangerTag);
        $entityManager->flush();
        $strangerTagId = $strangerTag->requireId();

        $subscriber = $factory->create('tagvictim@example.com');
        $ownTag = new Tag($subscriber, 'Mine');
        $entityManager->persist($ownTag);
        $entityManager->flush();
        $ownTagId = $ownTag->requireId();

        $rss = file_get_contents(__DIR__ . '/../../Fixtures/feeds/rss2-basic.xml');
        self::assertIsString($rss);
        $stub = new StubFeedFetcher();
        $stub->willReturn(
            'https://example.com/feed',
            FetchResponseModel::fetched(
                'https://example.com/feed.xml',
                permanentRedirect: false,
                body: $rss,
                etag: null,
                lastModified: null,
            ),
        );
        $this->installFetcher($stub);

        $headers = $this->bearerFor($subscriber);

        $client->request(
            'POST',
            '/api/subscriptions',
            server: $headers + ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(
                ['url' => 'https://example.com/feed', 'tagIds' => [$ownTagId, $strangerTagId]],
                \JSON_THROW_ON_ERROR,
            ),
        );
        self::assertResponseStatusCodeSame(201);
        $created = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($created);
        self::assertIsArray($created['subscription']);
        $tags = $created['subscription']['tags'];
        self::assertIsArray($tags);
        self::assertCount(1, $tags);
        self::assertIsArray($tags[0]);
        self::assertSame('Mine', $tags[0]['name']);
    }

    public function testSubscribingToAThrottledSiteIsServiceUnavailable(): void
    {
        $client = self::createClient();
        $headers = $this->bearerFor($this->factory()->create('slow@example.com'));
        $stub = new StubFeedFetcher();
        $stub->willThrow('https://slow.example.com/feed', new FeedThrottledException('429', 90));
        $this->installFetcher($stub);

        $client->request(
            'POST',
            '/api/subscriptions',
            server: $headers + ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['url' => 'https://slow.example.com/feed'], \JSON_THROW_ON_ERROR),
        );

        $this->assertRejected($client, Response::HTTP_SERVICE_UNAVAILABLE);
        self::assertSame('feed_throttled', $this->payload($client)['type'] ?? null);
    }

    public function testSubscribingAPageIsRefusedAsNotAFeed(): void
    {
        $client = self::createClient();
        $headers = $this->bearerFor($this->factory()->create('html@example.com'));
        $html = /** @lang TEXT */ '<!doctype html><html><head>'
            . '<link rel="alternate" type="application/rss+xml" href="/rss.xml">'
            . '</head><body>x</body></html>';
        $stub = new StubFeedFetcher();
        $stub->willReturn(
            'https://example.com/blog',
            FetchResponseModel::fetched(
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

        $this->assertRejected($client, Response::HTTP_UNPROCESSABLE_ENTITY);
        $problem = $this->payload($client);
        self::assertSame('not_a_feed', $problem['type'] ?? null);
        self::assertSame('No feed could be read at that address.', $problem['detail'] ?? null);
        self::assertSame(0, $this->entityManager()->getRepository(Subscription::class)->count([]));
    }

    public function testScrapedFormatSubscribeSkipsDiscoveryAndMarksTheFeed(): void
    {
        $client = self::createClient();
        $headers = $this->bearerFor($this->factory()->create('scraper@example.com'));
        $this->enableScrapeFallback('scraper@example.com');

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
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $feed = $entityManager->getRepository(Feed::class)->findOneBy(['url' => 'https://www.heise.de/']);
        self::assertInstanceOf(Feed::class, $feed);
        self::assertSame('scraped', $feed->getSourceFormat());
    }

    public function testScrapedFormatSubscribeStillEnforcesTheSubscriptionCap(): void
    {
        $client = self::createClient();
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        $user = $this->factory()->create('atcap@example.com');
        StoredUserSettings::reading($entityManager, $user)->setScrapeFallbackEnabled(true);
        $when = new \DateTimeImmutable('2026-07-01T00:00:00Z');
        for ($index = 0; $index < SubscriptionService::MAX_SUBSCRIPTIONS_PER_USER; $index++) {
            $feed = new Feed(sprintf('https://seed%d.example.com/feed.xml', $index));
            $entityManager->persist($feed);
            $entityManager->persist(new Subscription($user, $feed, $when));
        }
        $entityManager->flush();

        // Nothing stubbed: the scraped path never fetches, and if a regression
        // makes it fetch, the stub fails the test instead of hitting the net.
        $this->installFetcher(new StubFeedFetcher());

        $headers = $this->bearerFor($user);

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

    /**
     * A hand-made 'scraped' subscribe is refused while the preference is off, as discovery never offers one. Nothing
     * is stubbed, so a regression that reaches discovery or the extractor fails loudly.
     */
    public function testScrapedFormatSubscribeIsRefusedWhenScrapingIsDisabled(): void
    {
        $client = self::createClient();
        $headers = $this->bearerFor($this->factory()->create('scrapedisabled@example.com'));
        $this->installFetcher(new StubFeedFetcher());

        $client->request(
            'POST',
            '/api/subscriptions',
            server: $headers + ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['url' => 'https://www.heise.de/', 'format' => 'scraped'], \JSON_THROW_ON_ERROR),
        );

        self::assertResponseStatusCodeSame(403);
        $body = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($body);
        self::assertSame('scraping_disabled', $body['type']);

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        self::assertNull($entityManager->getRepository(Feed::class)->findOneBy(['url' => 'https://www.heise.de/']));
    }

    public function testWpJsonFormatSubscribeSeedsTheCandidateTitleWithoutFetching(): void
    {
        $client = self::createClient();
        $headers = $this->bearerFor($this->factory()->create('wpjson-title@example.com'));
        $stub = new StubFeedFetcher();
        $this->installFetcher($stub);
        $url = 'https://wp.example/wp-json/wp/v2/posts?per_page=20';

        $client->request(
            'POST',
            '/api/subscriptions',
            server: $headers + ['CONTENT_TYPE' => 'application/json'],
            content: json_encode([
                'url' => $url,
                'format' => 'wp-json',
                'title' => 'WordPress Example',
            ], \JSON_THROW_ON_ERROR),
        );

        self::assertResponseStatusCodeSame(201);
        self::assertSame([], $stub->fetchedUrls);
        $created = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($created);
        self::assertIsArray($created['subscription']);
        self::assertSame('WordPress Example', $created['subscription']['title']);
        self::assertSame('wp-json', $created['subscription']['sourceFormat']);

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $feed = $entityManager->getRepository(Feed::class)->findOneBy(['url' => $url]);
        self::assertInstanceOf(Feed::class, $feed);
        self::assertSame('WordPress Example', $feed->getTitle());
    }

    public function testCannotUpdateAnotherUsersSubscription(): void
    {
        $client = self::createClient();
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        $factory = $this->factory();
        $stranger = $factory->create('stranger@example.com');
        $feed = new Feed('https://example.com/x.xml');
        $entityManager->persist($feed);
        $subscription = new Subscription($stranger, $feed, new \DateTimeImmutable('2026-01-01T00:00:00Z'));
        $entityManager->persist($subscription);
        $entityManager->flush();

        $headers = $this->bearerFor($factory->create('attacker@example.com'));

        $client->request(
            'PATCH',
            '/api/subscriptions/' . $subscription->getId(),
            server: $headers + ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['customTitle' => 'hijacked', 'tagIds' => []], \JSON_THROW_ON_ERROR),
        );
        self::assertResponseStatusCodeSame(404); // not 403 — do not reveal existence
        self::assertStringContainsString(
            '"detail":"No such subscription."',
            (string) $client->getResponse()->getContent(),
        );
    }

    public function testPatchSetsIncludeInAllItemsFalse(): void
    {
        $client = self::createClient();
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        $user = $this->factory()->create('flagsetter@example.com');
        $feed = new Feed('https://flags.example.com/rss');
        $entityManager->persist($feed);
        $subscription = new Subscription($user, $feed, new \DateTimeImmutable('2026-01-01T00:00:00Z'));
        $entityManager->persist($subscription);
        $entityManager->flush();

        $headers = $this->bearerFor($user);

        $client->request(
            'PATCH',
            '/api/subscriptions/' . $subscription->getId(),
            server: $headers + ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(
                ['customTitle' => null, 'tagIds' => [], 'includeInAllItems' => false],
                \JSON_THROW_ON_ERROR,
            ),
        );
        self::assertResponseStatusCodeSame(200);
        $body = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($body);
        self::assertIsArray($body['subscription']);
        self::assertFalse($body['subscription']['includeInAllItems']);
        // The flag not sent in this request must survive untouched.
        self::assertTrue($body['subscription']['includeInForYou']);
    }

    public function testPatchSwitchesTheFeedOutOfTrendingAndLeavesTheOtherSurfaces(): void
    {
        $client = self::createClient();
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        $user = $this->factory()->create('trending-flag@example.com');
        $feed = new Feed('https://trending-flag.example.com/rss');
        $entityManager->persist($feed);
        $subscription = new Subscription($user, $feed, new \DateTimeImmutable('2026-01-01T00:00:00Z'));
        $entityManager->persist($subscription);
        $entityManager->flush();

        $client->request(
            'PATCH',
            '/api/subscriptions/' . $subscription->getId(),
            server: $this->bearerFor($user) + ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(
                ['customTitle' => null, 'tagIds' => [], 'includeInTrending' => false],
                \JSON_THROW_ON_ERROR,
            ),
        );
        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        $body = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($body);
        self::assertIsArray($body['subscription']);
        self::assertFalse($body['subscription']['includeInTrending']);
        self::assertTrue($body['subscription']['includeInAllItems']);
    }

    public function testOmittingAFlagLeavesItUnchanged(): void
    {
        $client = self::createClient();
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        $user = $this->factory()->create('flagkeeper@example.com');
        $feed = new Feed('https://flagkeeper.example.com/rss');
        $entityManager->persist($feed);
        $subscription = new Subscription($user, $feed, new \DateTimeImmutable('2026-01-01T00:00:00Z'));
        $subscription->changeInclusions(new InclusionChange(forYou: false));
        $entityManager->persist($subscription);
        $entityManager->flush();

        $headers = $this->bearerFor($user);

        $client->request(
            'PATCH',
            '/api/subscriptions/' . $subscription->getId(),
            server: $headers + ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['customTitle' => 'Kept Title', 'tagIds' => []], \JSON_THROW_ON_ERROR),
        );
        self::assertResponseStatusCodeSame(200);
        $body = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($body);
        self::assertIsArray($body['subscription']);
        // Omitted flag: null leaves the pre-existing false value alone.
        self::assertFalse($body['subscription']['includeInForYou']);
        self::assertSame('Kept Title', $body['subscription']['customTitle']);
    }

    /** Unlike the flags, an omitted tagIds still clears the feed's tags. */
    public function testTagClearOnOmissionStillHolds(): void
    {
        $client = self::createClient();
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        $user = $this->factory()->create('tagclearer@example.com');
        $tag = new Tag($user, 'Keepsake');
        $entityManager->persist($tag);
        $feed = new Feed('https://tagclearer.example.com/rss');
        $entityManager->persist($feed);
        $subscription = new Subscription($user, $feed, new \DateTimeImmutable('2026-01-01T00:00:00Z'));
        $subscription->addTag($tag);
        $entityManager->persist($subscription);
        $entityManager->flush();

        $headers = $this->bearerFor($user);

        $client->request(
            'PATCH',
            '/api/subscriptions/' . $subscription->getId(),
            server: $headers + ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['customTitle' => null], \JSON_THROW_ON_ERROR),
        );
        self::assertResponseStatusCodeSame(200);
        $body = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($body);
        self::assertIsArray($body['subscription']);
        self::assertSame([], $body['subscription']['tags']);
    }

    public function testUnsubscribingAsTheOnlySubscriberDeletesTheFeed(): void
    {
        $client = self::createClient();
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        $user = $this->factory()->create('solo@example.com');
        $feed = new Feed('https://solo.example.com/rss');
        $entityManager->persist($feed);
        $subscription = new Subscription($user, $feed, new \DateTimeImmutable('2026-01-01T00:00:00Z'));
        $entityManager->persist($subscription);
        $entityManager->flush();
        $feedId = $feed->requireId();

        $headers = $this->bearerFor($user);

        $client->request(
            'DELETE',
            '/api/subscriptions/' . $subscription->getId(),
            server: $headers,
        );

        self::assertResponseStatusCodeSame(204);
        // reclaim() deletes by bulk DQL, past the unit of work, and one request never reboots the kernel: clear the
        // controller's identity map, or find() returns the deleted Feed without asking the database.
        $entityManager->clear();
        self::assertNull($entityManager->getRepository(Feed::class)->find($feedId));
    }

    public function testUnsubscribingKeepsAFeedAnotherUserStillReads(): void
    {
        $client = self::createClient();
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        $factory = $this->factory();
        $leaver = $factory->create('leaver@example.com');
        $stayer = $factory->create('stayer@example.com');
        $feed = new Feed('https://shared.example.com/rss');
        $entityManager->persist($feed);
        $leaving = new Subscription($leaver, $feed, new \DateTimeImmutable('2026-01-01T00:00:00Z'));
        $entityManager->persist($leaving);
        $entityManager->persist(new Subscription($stayer, $feed, new \DateTimeImmutable('2026-01-01T00:00:00Z')));
        $entityManager->flush();
        $feedId = $feed->requireId();

        $headers = $this->bearerFor($leaver);

        $client->request(
            'DELETE',
            '/api/subscriptions/' . $leaving->getId(),
            server: $headers,
        );

        self::assertResponseStatusCodeSame(204);
        // Same identity-map trap: without the clear, find() passes even if reclaim() wrongly deleted the row.
        $entityManager->clear();
        self::assertNotNull($entityManager->getRepository(Feed::class)->find($feedId));
    }
}
