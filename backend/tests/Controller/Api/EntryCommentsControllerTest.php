<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Entity\Entry;
use App\Entity\Feed;
use App\Entity\Subscription;
use App\Entity\User;
use App\Enum\CommentsLoad;
use App\Service\Discussion\Discussion;
use App\Service\Fetch\Exception\FeedThrottledException;
use App\Service\Fetch\FeedFetcherInterface;
use App\Service\Fetch\FetchResponse;
use App\Tests\Support\StubFeedFetcher;
use App\Tests\Support\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class EntryCommentsControllerTest extends WebTestCase
{
    private const string THREAD = 'https://www.reddit.com/r/PHP/comments/1woq4he/what_is_your_php_stack_2026/';
    private const string FEED = self::THREAD . '.rss';

    protected function setUp(): void
    {
        self::bootKernel();
        $rateLimiterCache = self::getContainer()->get('test.cache.rate_limiter');
        self::assertInstanceOf(CacheItemPoolInterface::class, $rateLimiterCache);
        $rateLimiterCache->clear();
        $hostThrottleCache = self::getContainer()->get('host_throttle.cache');
        self::assertInstanceOf(CacheItemPoolInterface::class, $hostThrottleCache);
        $hostThrottleCache->clear();
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

    private function seedEntry(User $user, bool $withCommentsFeed): Entry
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        $feed = new Feed('https://www.reddit.com/r/PHP/.rss');
        $feed->setTitle('Seeded');
        $em->persist($feed);
        $em->persist(new Subscription($user, $feed, new \DateTimeImmutable('2026-07-01T00:00:00Z')));

        $entry = new Entry(
            $feed,
            't3_1woq4he',
            null,
            'Stack',
            new \DateTimeImmutable('2026-07-01T00:00:00Z'),
            new \DateTimeImmutable('2026-07-01T00:00:00Z'),
        );
        $entry->setAuthor('/u/Background_Lie11');
        if ($withCommentsFeed) {
            $entry->setDiscussion(Discussion::withCommentsFeed(self::THREAD, self::FEED, CommentsLoad::Auto));
        }
        $em->persist($entry);
        $em->flush();

        return $entry;
    }

    private function installFetcher(): StubFeedFetcher
    {
        $fetcher = new StubFeedFetcher();
        self::getContainer()->set(FeedFetcherInterface::class, $fetcher);

        return $fetcher;
    }

    public function testOkReturnsComments(): void
    {
        $client = self::createClient();
        [$headers, $user] = $this->auth('comments-ok@example.com');
        $fetcher = $this->installFetcher();
        $xml = (string) file_get_contents(__DIR__ . '/../../Fixtures/reddit/thread-comments.atom');
        $fetcher->willReturn(self::FEED, FetchResponse::fetched(self::FEED, false, $xml, null, null));
        $entry = $this->seedEntry($user, true);

        $client->request('GET', '/api/entries/' . $entry->getId() . '/comments', server: $headers);

        self::assertResponseIsSuccessful();
        $body = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($body);
        self::assertSame('ok', $body['status']);
        self::assertSame(self::THREAD, $body['discussionUrl']);
        self::assertIsArray($body['comments']);
        self::assertNotSame([], $body['comments']);
        $firstComment = $body['comments'][0];
        self::assertIsArray($firstComment);
        self::assertSame(
            ['author', 'authorUrl', 'url', 'publishedAt', 'html', 'byEntryAuthor'],
            array_keys($firstComment),
        );
    }

    public function testThrottledCarriesRetryAfter(): void
    {
        $client = self::createClient();
        [$headers, $user] = $this->auth('comments-throttled@example.com');
        $fetcher = $this->installFetcher();
        $fetcher->willThrow(self::FEED, new FeedThrottledException('429', 30));
        $entry = $this->seedEntry($user, true);

        $client->request('GET', '/api/entries/' . $entry->getId() . '/comments', server: $headers);

        self::assertResponseIsSuccessful();
        $body = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($body);
        self::assertSame('throttled', $body['status']);
        self::assertSame(30, $body['retryAfter']);
    }

    public function testEntryWithoutCommentsFeedIs404(): void
    {
        $client = self::createClient();
        [$headers, $user] = $this->auth('comments-nofeed@example.com');
        $this->installFetcher();
        $entry = $this->seedEntry($user, false);

        $client->request('GET', '/api/entries/' . $entry->getId() . '/comments', server: $headers);

        self::assertResponseStatusCodeSame(404);
        self::assertSame('application/problem+json', $client->getResponse()->headers->get('Content-Type'));
    }

    public function testSomeoneElsesEntryIs404(): void
    {
        $client = self::createClient();
        [$headers] = $this->auth('comments-idor@example.com');
        [, $stranger] = $this->auth('comments-owner@example.com');
        $this->installFetcher();
        $entry = $this->seedEntry($stranger, true);

        $client->request('GET', '/api/entries/' . $entry->getId() . '/comments', server: $headers);

        self::assertResponseStatusCodeSame(404);
    }

    public function testUnauthenticatedIs401(): void
    {
        $client = self::createClient();
        $client->request('GET', '/api/entries/1/comments');
        self::assertResponseStatusCodeSame(401);
    }
}
