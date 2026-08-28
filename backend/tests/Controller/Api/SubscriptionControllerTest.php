<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Entity\Feed;
use App\Entity\Subscription;
use App\Entity\Tag;
use App\Entity\User;
use App\Service\Fetch\BatchFeedFetcherInterface;
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
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $user = $em->getRepository(User::class)->findOneBy(['email' => User::normalizeEmail($email)]);
        self::assertInstanceOf(User::class, $user);
        $user->getPreferences()->setScrapeFallbackEnabled(true);
        $em->flush();
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
        // Discovery read the document to confirm it was a feed, so the entries
        // are already there when the dialog closes (#290).
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
        // entries rather than empty until some later refresh (#290).
        self::assertSame(2, $first['unreadCount']);
        // Sidebar favourite/kept badge totals travel on the same payload.
        self::assertSame(0, $list['favoritesCount']);
        self::assertSame(0, $list['keptCount']);
    }

    public function testSubscribeWithTagIdsCreatesAlreadyTaggedFeed(): void
    {
        $client = self::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        $user = $this->userFactory()->create('withtags@example.com');
        $news = new Tag($user, 'News');
        $tech = new Tag($user, 'Tech');
        $em->persist($news);
        $em->persist($tech);
        $em->flush();
        $newsId = (int) $news->getId();
        $techId = (int) $tech->getId();

        $rss = file_get_contents(__DIR__ . '/../../Fixtures/feeds/rss2-basic.xml');
        self::assertIsString($rss);
        $stub = new StubFeedFetcher();
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

        $tokens = self::getContainer()->get(JWTTokenManagerInterface::class);
        self::assertInstanceOf(JWTTokenManagerInterface::class, $tokens);
        $headers = ['HTTP_AUTHORIZATION' => 'Bearer ' . $tokens->create($user)];

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
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        $factory = $this->userFactory();
        $stranger = $factory->create('tagstranger@example.com');
        $strangerTag = new Tag($stranger, 'Secret');
        $em->persist($strangerTag);
        $em->flush();
        $strangerTagId = (int) $strangerTag->getId();

        $subscriber = $factory->create('tagvictim@example.com');
        $ownTag = new Tag($subscriber, 'Mine');
        $em->persist($ownTag);
        $em->flush();
        $ownTagId = (int) $ownTag->getId();

        $rss = file_get_contents(__DIR__ . '/../../Fixtures/feeds/rss2-basic.xml');
        self::assertIsString($rss);
        $stub = new StubFeedFetcher();
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

        $tokens = self::getContainer()->get(JWTTokenManagerInterface::class);
        self::assertInstanceOf(JWTTokenManagerInterface::class, $tokens);
        $headers = ['HTTP_AUTHORIZATION' => 'Bearer ' . $tokens->create($subscriber)];

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

    public function testSubscribeToHtmlReturnsCandidates(): void
    {
        $client = self::createClient();
        $headers = $this->authHeader('html@example.com');

        // `/rss.xml` is a deliberately fake path, because discovering it is what
        // the test is about, so `@lang TEXT` stops PhpStorm injecting HTML here
        // and reporting the target as unresolvable.
        $html = /** @lang TEXT */ '<!doctype html><html><head>'
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
        $this->enableScrapeFallback('feedless@example.com');

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
        // Full-shape pin (like the blocked test): exactly one candidate with
        // exactly these keys, the page's og:site_name as title, and no
        // scrapeFailureReason key on success.
        self::assertSame(
            ['candidates' => [
                ['url' => 'https://www.heise.de/', 'title' => 'heise online', 'format' => 'scraped'],
            ]],
            $body,
        );
    }

    /**
     * The scrape fallback defaults to OFF, so this user's preference is left
     * untouched — a DI wiring regression that never consults
     * ScrapeFallbackPolicy would offer the scraped candidate anyway and this
     * test would catch it.
     */
    public function testAFeedlessPageOffersNoScrapedCandidateWhenTheFallbackIsDisabled(): void
    {
        $client = self::createClient();
        $headers = $this->authHeader('feedlessdisabled@example.com');

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
        // 'not_scrapable' here would leak the disabled feature into the UI —
        // the key must be entirely absent, not merely null.
        self::assertSame(['candidates' => []], $body);
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
        $user->getPreferences()->setScrapeFallbackEnabled(true);
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

    /**
     * The bypass Task 6 closes: a hand-made request setting format 'scraped'
     * must be refused for an account with the preference off, exactly as
     * discovery already refuses to OFFER such a candidate to that account.
     * Nothing is stubbed on the fetcher, so a regression that lets the
     * request reach discovery or the extractor fails loudly here rather than
     * silently creating a subscription.
     */
    public function testScrapedFormatSubscribeIsRefusedWhenScrapingIsDisabled(): void
    {
        $client = self::createClient();
        $headers = $this->authHeader('scrapedisabled@example.com');
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

        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        self::assertNull($em->getRepository(Feed::class)->findOneBy(['url' => 'https://www.heise.de/']));
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

    public function testPatchSetsIncludeInAllItemsFalse(): void
    {
        $client = self::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        $user = $this->userFactory()->create('flagsetter@example.com');
        $feed = new Feed('https://flags.example.com/rss');
        $em->persist($feed);
        $sub = new Subscription($user, $feed, new \DateTimeImmutable('2026-01-01T00:00:00Z'));
        $em->persist($sub);
        $em->flush();

        $tokens = self::getContainer()->get(JWTTokenManagerInterface::class);
        self::assertInstanceOf(JWTTokenManagerInterface::class, $tokens);
        $headers = ['HTTP_AUTHORIZATION' => 'Bearer ' . $tokens->create($user)];

        $client->request(
            'PATCH',
            '/api/subscriptions/' . $sub->getId(),
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

    public function testOmittingAFlagLeavesItUnchanged(): void
    {
        $client = self::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        $user = $this->userFactory()->create('flagkeeper@example.com');
        $feed = new Feed('https://flagkeeper.example.com/rss');
        $em->persist($feed);
        $sub = new Subscription($user, $feed, new \DateTimeImmutable('2026-01-01T00:00:00Z'));
        $sub->setIncludeInForYou(false);
        $em->persist($sub);
        $em->flush();

        $tokens = self::getContainer()->get(JWTTokenManagerInterface::class);
        self::assertInstanceOf(JWTTokenManagerInterface::class, $tokens);
        $headers = ['HTTP_AUTHORIZATION' => 'Bearer ' . $tokens->create($user)];

        $client->request(
            'PATCH',
            '/api/subscriptions/' . $sub->getId(),
            server: $headers + ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['customTitle' => 'Kept Title', 'tagIds' => []], \JSON_THROW_ON_ERROR),
        );
        self::assertResponseStatusCodeSame(200);
        $body = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($body);
        self::assertIsArray($body['subscription']);
        // Omitted flag: null leaves the pre-existing false value alone.
        self::assertFalse($body['subscription']['includeInForYou']);
        // customTitle keeps its existing (unrelated) clear-on-omission-free
        // apply-what-was-sent behaviour.
        self::assertSame('Kept Title', $body['subscription']['customTitle']);
    }

    /**
     * Regression guard: customTitle/tagIds must keep their existing
     * clear-on-omission semantics — a PATCH body without tagIds still wipes
     * the feed's tags, exactly as before the two flags were added.
     */
    public function testTagClearOnOmissionStillHolds(): void
    {
        $client = self::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        $user = $this->userFactory()->create('tagclearer@example.com');
        $tag = new Tag($user, 'Keepsake');
        $em->persist($tag);
        $feed = new Feed('https://tagclearer.example.com/rss');
        $em->persist($feed);
        $sub = new Subscription($user, $feed, new \DateTimeImmutable('2026-01-01T00:00:00Z'));
        $sub->addTag($tag);
        $em->persist($sub);
        $em->flush();

        $tokens = self::getContainer()->get(JWTTokenManagerInterface::class);
        self::assertInstanceOf(JWTTokenManagerInterface::class, $tokens);
        $headers = ['HTTP_AUTHORIZATION' => 'Bearer ' . $tokens->create($user)];

        $client->request(
            'PATCH',
            '/api/subscriptions/' . $sub->getId(),
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
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        $user = $this->userFactory()->create('solo@example.com');
        $feed = new Feed('https://solo.example.com/rss');
        $em->persist($feed);
        $subscription = new Subscription($user, $feed, new \DateTimeImmutable('2026-01-01T00:00:00Z'));
        $em->persist($subscription);
        $em->flush();
        $feedId = (int) $feed->getId();

        $tokens = self::getContainer()->get(JWTTokenManagerInterface::class);
        self::assertInstanceOf(JWTTokenManagerInterface::class, $tokens);
        $headers = ['HTTP_AUTHORIZATION' => 'Bearer ' . $tokens->create($user)];

        $client->request(
            'DELETE',
            '/api/subscriptions/' . $subscription->getId(),
            server: $headers,
        );

        self::assertResponseStatusCodeSame(204);
        // A single-request test never reboots the kernel (KernelBrowser only
        // reboots from the second request on), so this is still the SAME
        // EntityManager the controller used. reclaim() deletes via bulk DQL,
        // which bypasses the unit of work, so $em's identity map still holds
        // the now-deleted Feed; find() would return it without ever touching
        // the database unless the map is cleared first.
        $em->clear();
        self::assertNull($em->getRepository(Feed::class)->find($feedId));
    }

    public function testUnsubscribingKeepsAFeedAnotherUserStillReads(): void
    {
        $client = self::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        $factory = $this->userFactory();
        $leaver = $factory->create('leaver@example.com');
        $stayer = $factory->create('stayer@example.com');
        $feed = new Feed('https://shared.example.com/rss');
        $em->persist($feed);
        $leaving = new Subscription($leaver, $feed, new \DateTimeImmutable('2026-01-01T00:00:00Z'));
        $em->persist($leaving);
        $em->persist(new Subscription($stayer, $feed, new \DateTimeImmutable('2026-01-01T00:00:00Z')));
        $em->flush();
        $feedId = (int) $feed->getId();

        $tokens = self::getContainer()->get(JWTTokenManagerInterface::class);
        self::assertInstanceOf(JWTTokenManagerInterface::class, $tokens);
        $headers = ['HTTP_AUTHORIZATION' => 'Bearer ' . $tokens->create($leaver)];

        $client->request(
            'DELETE',
            '/api/subscriptions/' . $leaving->getId(),
            server: $headers,
        );

        self::assertResponseStatusCodeSame(204);
        // Same identity-map trap as the sibling test above: a single-request
        // test never reboots the kernel, so $em is still the exact instance
        // the controller used. Without clearing it, find() would return the
        // pre-request Feed object straight out of the identity map and this
        // assertion would pass even if reclaim() wrongly deleted the row —
        // proving nothing about the guard this test exists to cover.
        $em->clear();
        self::assertNotNull($em->getRepository(Feed::class)->find($feedId));
    }
}
