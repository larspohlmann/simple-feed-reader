<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Dto\Entry\CompleteEntriesRequest;
use App\Entity\Entry;
use App\Entity\Feed;
use App\Entity\PendingPostEnrichment;
use App\Entity\Subscription;
use App\Entity\User;
use App\Repository\TriedPostRepository;
use App\Service\Bluesky\AppViewClient;
use App\Service\Bluesky\PostEnricher;
use App\Service\Fetch\HostThrottle;
use App\Service\LinkCard\PostLinkCards;
use App\Tests\Support\ApiTestCase;
use App\Tests\Support\Bluesky;
use App\Tests\Support\FakeAppView;
use App\Tests\Support\PostEnrichers;
use App\Tests\Support\PostLinkCardsWiring;
use PHPUnit\Framework\Attributes\DataProvider;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

final class EntryCompletionControllerTest extends ApiTestCase
{
    private const string TISCH = 'at://did:plc:qobvnkudcv3zlaklxxjduqoi/app.bsky.feed.post/3mxjuesq6v62t';

    private KernelBrowser $client;
    private FakeAppView $appView;
    private HostThrottle $throttle;
    private MockHttpClient $pages;
    private MockClock $clock;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->clearPool('test.cache.rate_limiter');
        $this->clearPool('test.cache.post_link_cards');
        $this->clock = new MockClock('2026-10-10 12:00:00', 'UTC');
        $this->appView = new FakeAppView();
        $this->throttle = new HostThrottle(new ArrayAdapter(clock: $this->clock), $this->clock);
        $this->pages = new MockHttpClient(self::page(...));
        $this->installFakes();
    }

    public function testPendingPostsFillAndComeBackSettled(): void
    {
        [$headers, $user] = $this->auth('complete-fill@example.com');
        $feed = $this->subscribedFeed($user);
        $blueskyPost = $this->queuedPost($feed, self::TISCH);
        $linkPost = $this->post($feed, 'https://mastodon.example/@a/1', '<p>See https://example.com/story</p>');
        $this->appView->knowsFixture('external');

        $this->client->request('GET', '/api/entries', server: $headers);
        self::assertSame(
            [$blueskyPost->requireId() => true, $linkPost->requireId() => true],
            $this->pendingById($this->payload($this->client)['entries']),
        );

        $this->complete($headers, [$blueskyPost->requireId(), $linkPost->requireId()]);

        self::assertResponseIsSuccessful();
        $entries = $this->payload($this->client)['entries'];
        self::assertSame(
            [$blueskyPost->requireId() => false, $linkPost->requireId() => false],
            $this->pendingById($entries),
        );
        self::assertSame([[self::TISCH]], $this->appView->requests);
        self::assertSame(1, $this->pages->getRequestsCount());
        self::assertSame(['Card for https://example.com/story'], $this->cardTitlesOf($entries, $linkPost));
        self::assertSame([Bluesky::CARD], $this->cardUrlsOf($entries, $blueskyPost));
    }

    public function testAStrangersIdsAreDroppedWithoutWork(): void
    {
        [$headers, $user] = $this->auth('complete-own@example.com');
        [, $stranger] = $this->auth('complete-stranger@example.com');
        $own = $this->post($this->subscribedFeed($user), 'https://mastodon.example/@a/1', '<p>Just words.</p>');
        $strangersFeed = $this->subscribedFeed($stranger);
        $strangersBlueskyPost = $this->queuedPost($strangersFeed, self::TISCH);
        $strangersLinkPost = $this->post(
            $strangersFeed,
            'https://mastodon.example/@b/1',
            '<p>See https://example.com/story</p>',
        );

        $this->complete(
            $headers,
            [$own->requireId(), $strangersBlueskyPost->requireId(), $strangersLinkPost->requireId()],
        );

        self::assertResponseIsSuccessful();
        self::assertSame([$own->requireId() => false], $this->pendingById($this->payload($this->client)['entries']));
        self::assertSame([], $this->appView->requests);
        self::assertSame(0, $this->pages->getRequestsCount());
    }

    public function testACallSpendsAtMostFivePageFetches(): void
    {
        [$headers, $user] = $this->auth('complete-budget@example.com');
        $feed = $this->subscribedFeed($user);
        $links = static fn (int $post): string => '<p>' . implode(' ', array_map(
            static fn (int $link): string => 'https://example.com/' . $post . '/' . $link,
            range(1, 4),
        )) . '</p>';
        $first = $this->post($feed, 'https://mastodon.example/@a/1', $links(1));
        $second = $this->post($feed, 'https://mastodon.example/@a/2', $links(2));

        $this->complete($headers, [$first->requireId(), $second->requireId()]);

        self::assertResponseIsSuccessful();
        self::assertSame(5, $this->pages->getRequestsCount());
        $pending = $this->pendingById($this->payload($this->client)['entries']);
        self::assertEqualsCanonicalizing([false, true], array_values($pending));
    }

    public function testAThrottledAppViewLeavesThePostQueuedAndPending(): void
    {
        [$headers, $user] = $this->auth('complete-throttled@example.com');
        $blueskyPost = $this->queuedPost($this->subscribedFeed($user), self::TISCH);
        $this->throttle->record(Bluesky::GET_POSTS, 120);

        $this->complete($headers, [$blueskyPost->requireId()]);

        self::assertResponseIsSuccessful();
        self::assertSame(
            [$blueskyPost->requireId() => true],
            $this->pendingById($this->payload($this->client)['entries']),
        );
        self::assertSame([], $this->appView->requests);
        self::assertSame(1, $this->entityManager()->getRepository(PendingPostEnrichment::class)->count([]));
    }

    public function testMoreThanTwentyFiveIdsAreRejected(): void
    {
        [$headers] = $this->auth('complete-cap@example.com');

        $this->complete($headers, range(1, CompleteEntriesRequest::MAX_IDS + 1));

        $this->assertRejected($this->client, 422);
    }

    /** @return iterable<string, array{array<mixed>}> */
    public static function invalidBodies(): iterable
    {
        yield 'no ids' => [['ids' => []]];
        yield 'a zero id' => [['ids' => [0]]];
        yield 'a string id' => [['ids' => ['1']]];
    }

    /** @param array<mixed> $body */
    #[DataProvider('invalidBodies')]
    public function testAnInvalidBodyIsRejected(array $body): void
    {
        [$headers] = $this->auth('complete-invalid@example.com');

        $this->client->request(
            'POST',
            '/api/entries/complete',
            server: $headers + ['CONTENT_TYPE' => 'application/json'],
            content: json_encode($body, \JSON_THROW_ON_ERROR),
        );

        $this->assertRejected($this->client, 422);
    }

    public function testASpentCompletionBudgetIsRateLimitedWithoutWork(): void
    {
        [$headers, $user] = $this->auth('complete-limited@example.com');
        $blueskyPost = $this->queuedPost($this->subscribedFeed($user), self::TISCH);
        $this->limiter('limiter.entry_completion')->create('user-' . $user->requireId())->consume(120);

        $this->complete($headers, [$blueskyPost->requireId()]);

        $this->assertRejected($this->client, 429);
        self::assertSame([], $this->appView->requests);
    }

    public function testASpentReaderBudgetDoesNotBlockCompleting(): void
    {
        [$headers, $user] = $this->auth('complete-reader-spent@example.com');
        $post = $this->post($this->subscribedFeed($user), 'https://mastodon.example/@a/1', '<p>Just words.</p>');
        $this->limiter('limiter.reader')->create('user-' . $user->requireId())->consume(60);

        $this->complete($headers, [$post->requireId()]);

        self::assertResponseIsSuccessful();
    }

    public function testCompletingSpendsNothingOfTheReaderBudget(): void
    {
        [$headers, $user] = $this->auth('complete-reader-kept@example.com');
        $post = $this->post($this->subscribedFeed($user), 'https://mastodon.example/@a/1', '<p>Just words.</p>');
        $readerBudget = $this->limiter('limiter.reader')->create('user-' . $user->requireId());
        self::assertTrue($readerBudget->consume(59)->isAccepted());

        $this->complete($headers, [$post->requireId()]);

        self::assertResponseIsSuccessful();
        self::assertTrue($readerBudget->consume()->isAccepted());
    }

    private function limiter(string $id): RateLimiterFactoryInterface
    {
        $limiter = self::getContainer()->get($id);
        self::assertInstanceOf(RateLimiterFactoryInterface::class, $limiter);

        return $limiter;
    }

    public function testAnAnonymousCallIsUnauthorized(): void
    {
        $this->complete([], [1]);

        self::assertResponseStatusCodeSame(401);
    }

    /**
     * @param array<string, string> $headers
     * @param list<int> $ids
     */
    private function complete(array $headers, array $ids): void
    {
        $this->client->request(
            'POST',
            '/api/entries/complete',
            server: $headers + ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['ids' => $ids], \JSON_THROW_ON_ERROR),
        );
    }

    private function clearPool(string $id): void
    {
        $pool = self::getContainer()->get($id);
        self::assertInstanceOf(CacheItemPoolInterface::class, $pool);
        $pool->clear();
    }

    private function installFakes(): void
    {
        $container = self::getContainer();
        $triedPosts = $container->get(TriedPostRepository::class);
        self::assertInstanceOf(TriedPostRepository::class, $triedPosts);
        $container->set(
            PostEnricher::class,
            PostEnrichers::build(
                $this->entityManager(),
                $this->clock,
                new AppViewClient($this->appView, $this->throttle),
                new NullLogger(),
            ),
        );
        $container->set(
            PostLinkCards::class,
            PostLinkCardsWiring::sharingTriedPosts($this->entityManager(), $this->pages, $triedPosts),
        );
    }

    private static function page(string $method, string $url): MockResponse
    {
        return new MockResponse(
            '<html lang="en"><head><meta property="og:title" content="Card for ' . $url . '"></head></html>',
            ['http_code' => 200, 'response_headers' => ['content-type' => 'text/html']],
        );
    }

    /** @return array{0: array<string, string>, 1: User} */
    private function auth(string $email): array
    {
        $user = $this->factory()->create($email);
        $tokens = self::getContainer()->get(JWTTokenManagerInterface::class);
        self::assertInstanceOf(JWTTokenManagerInterface::class, $tokens);

        return [['HTTP_AUTHORIZATION' => 'Bearer ' . $tokens->create($user)], $user];
    }

    private function subscribedFeed(User $user): Feed
    {
        $feed = new Feed('https://f.test/' . uniqid('', true));
        $this->entityManager()->persist($feed);
        $this->entityManager()->persist(new Subscription($user, $feed, new \DateTimeImmutable('2026-07-01')));
        $this->entityManager()->flush();

        return $feed;
    }

    private function queuedPost(Feed $feed, string $guid): Entry
    {
        $post = $this->post($feed, $guid, '<p>Read this.</p>');
        $this->entityManager()->persist(new PendingPostEnrichment($post, new \DateTimeImmutable('2026-10-10 11:00')));
        $this->entityManager()->flush();

        return $post;
    }

    private function post(Feed $feed, string $guid, string $contentHtml): Entry
    {
        $createdAt = new \DateTimeImmutable('-1 hour', new \DateTimeZone('UTC'));
        $post = new Entry($feed, $guid, null, 'Post', $createdAt, $createdAt);
        $post->setContentHtml($contentHtml);
        $post->markTitleDerived();
        $post->getImage()->storePending(null, null, null);
        $this->entityManager()->persist($post);
        $this->entityManager()->flush();

        return $post;
    }

    /** @return array<int, bool> */
    private function pendingById(mixed $rows): array
    {
        self::assertIsArray($rows);
        $pending = [];
        foreach ($rows as $row) {
            self::assertIsArray($row);
            self::assertIsInt($row['id']);
            self::assertIsBool($row['pending']);
            $pending[$row['id']] = $row['pending'];
        }
        ksort($pending);

        return $pending;
    }

    /** @return list<mixed> */
    private function cardTitlesOf(mixed $rows, Entry $entry): array
    {
        return array_column($this->cardsOf($rows, $entry), 'title');
    }

    /** @return list<mixed> */
    private function cardUrlsOf(mixed $rows, Entry $entry): array
    {
        return array_column($this->cardsOf($rows, $entry), 'url');
    }

    /** @return array<array-key, array<array-key, mixed>> */
    private function cardsOf(mixed $rows, Entry $entry): array
    {
        self::assertIsArray($rows);
        foreach ($rows as $row) {
            self::assertIsArray($row);
            if ($row['id'] === $entry->requireId()) {
                self::assertIsArray($row['linkCards']);

                /** @var array<array-key, array<array-key, mixed>> */
                return $row['linkCards'];
            }
        }
        self::fail('No row for entry ' . $entry->requireId());
    }
}
