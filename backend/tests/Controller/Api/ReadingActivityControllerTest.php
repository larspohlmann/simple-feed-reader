<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Entity\Entry;
use App\Entity\EntryState;
use App\Entity\Feed;
use App\Entity\Subscription;
use App\Entity\User;
use App\Tests\Support\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * The reading-activity chart's endpoint (#896): thirty days of per-day open
 * counts in the viewer's zone. Read-only, so ownership is proven the way the
 * run-history endpoint proves it — an anonymous request is refused, and the
 * window drops opens that fall outside it.
 */
final class ReadingActivityControllerTest extends WebTestCase
{
    private const string ROUTE = '/api/reading/activity';

    /** @return array{0: array<string,string>, 1: User} */
    private function auth(string $email): array
    {
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        self::assertInstanceOf(UserPasswordHasherInterface::class, $hasher);
        $user = (new UserFactory($this->em(), $hasher))->create($email);

        $tokens = self::getContainer()->get(JWTTokenManagerInterface::class);
        self::assertInstanceOf(JWTTokenManagerInterface::class, $tokens);

        return [['HTTP_AUTHORIZATION' => 'Bearer ' . $tokens->create($user)], $user];
    }

    private function em(): EntityManagerInterface
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        return $em;
    }

    /** @return array<string, mixed> */
    private function payload(Response $response): array
    {
        $decoded = json_decode((string) $response->getContent(), true);
        self::assertIsArray($decoded);

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    private function openArticle(User $user, Feed $feed, string $guid, \DateTimeImmutable $openedAt): void
    {
        $createdAt = new \DateTimeImmutable('2026-01-01T00:00:00Z');
        $entry = new Entry($feed, $guid, null, $guid, $createdAt, $createdAt);
        $this->em()->persist($entry);

        $state = new EntryState($user, $entry);
        $state->markViewed($openedAt);
        $this->em()->persist($state);
    }

    public function testRefusesAnAnonymousRequest(): void
    {
        $client = self::createClient();

        $client->request('GET', self::ROUTE);

        self::assertResponseStatusCodeSame(401);
    }

    public function testAnAccountWithNoReadsGetsThirtyZeroDays(): void
    {
        $client = self::createClient();
        [$headers] = $this->auth('reading-activity-empty@example.test');

        $client->request('GET', self::ROUTE . '?tz=UTC', server: $headers);

        self::assertResponseIsSuccessful();
        $payload = $this->payload($client->getResponse());
        /** @var list<array{date: string, count: int}> $days */
        $days = $payload['days'];
        self::assertCount(30, $days);
        self::assertSame(0, $payload['total']);
        self::assertSame([0], array_values(array_unique(array_column($days, 'count'))));
        self::assertSame([], $payload['topFeedsByRead']);
    }

    public function testRanksTopFeedsByReadBusiestFirst(): void
    {
        $client = self::createClient();
        [$headers, $user] = $this->auth('reading-activity-top-read@example.test');

        $busy = new Feed('https://example.com/busy.xml');
        $quiet = new Feed('https://example.com/quiet.xml');
        $this->em()->persist($busy);
        $this->em()->persist($quiet);
        $subscribedAt = new \DateTimeImmutable('2026-01-01T00:00:00Z');
        $this->em()->persist(new Subscription($user, $busy, $subscribedAt));
        $this->em()->persist(new Subscription($user, $quiet, $subscribedAt));

        $when = new \DateTimeImmutable('2026-06-01T09:00:00Z');
        $this->openArticle($user, $busy, 'busy-a', $when);
        $this->openArticle($user, $busy, 'busy-b', $when);
        $this->openArticle($user, $quiet, 'quiet-a', $when);
        $this->em()->flush();

        $client->request('GET', self::ROUTE . '?tz=UTC', server: $headers);

        self::assertResponseIsSuccessful();
        $payload = $this->payload($client->getResponse());
        self::assertSame(
            [
                ['feedId' => $busy->getId(), 'readCount' => 2],
                ['feedId' => $quiet->getId(), 'readCount' => 1],
            ],
            $payload['topFeedsByRead'],
        );
    }

    public function testCountsOpensPerDayWithinTheWindowAndDropsOlderOnes(): void
    {
        $client = self::createClient();
        [$headers, $user] = $this->auth('reading-activity-counts@example.test');

        $feed = new Feed('https://example.com/f.xml');
        $this->em()->persist($feed);
        $this->em()->persist(new Subscription($user, $feed, new \DateTimeImmutable('2026-01-01T00:00:00Z')));

        // Midday UTC keeps every open clear of a day boundary under tz=UTC.
        $noonUtc = new \DateTimeImmutable('now', new \DateTimeZone('UTC'))->setTime(12, 0);
        $twoDaysAgo = $noonUtc->modify('-2 days');
        $fiveDaysAgo = $noonUtc->modify('-5 days');

        $this->openArticle($user, $feed, 'recent-a', $twoDaysAgo);
        $this->openArticle($user, $feed, 'recent-b', $twoDaysAgo);
        $this->openArticle($user, $feed, 'older', $fiveDaysAgo);
        // Well outside the thirty-day window, so it must not be counted.
        $this->openArticle($user, $feed, 'ancient', $noonUtc->modify('-40 days'));
        $this->em()->flush();

        $client->request('GET', self::ROUTE . '?tz=UTC', server: $headers);

        self::assertResponseIsSuccessful();
        $payload = $this->payload($client->getResponse());
        self::assertSame(3, $payload['total']);

        /** @var list<array{date: string, count: int}> $days */
        $days = $payload['days'];
        $byDate = array_column($days, 'count', 'date');
        self::assertSame(2, $byDate[$twoDaysAgo->format('Y-m-d')]);
        self::assertSame(1, $byDate[$fiveDaysAgo->format('Y-m-d')]);
        self::assertArrayNotHasKey($noonUtc->modify('-40 days')->format('Y-m-d'), $byDate);
    }
}
