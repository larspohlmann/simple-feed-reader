<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Entity\Entry;
use App\Entity\Feed;
use App\Entity\Subscription;
use App\Entity\User;
use App\Tests\Support\ApiTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;

final class EntrySearchControllerTest extends ApiTestCase
{
    /** @return array{0: array<string,string>, 1: User} */
    private function auth(string $email): array
    {
        $user = $this->factory()->create($email);

        $tokens = self::getContainer()->get(JWTTokenManagerInterface::class);
        self::assertInstanceOf(JWTTokenManagerInterface::class, $tokens);

        return [['HTTP_AUTHORIZATION' => 'Bearer ' . $tokens->create($user)], $user];
    }

    /**
     * Seeds a feed the given user is subscribed to, with $count entries whose
     * titles are "$titlePrefix $i" and whose effective dates are distinct days,
     * so ordering and cursor paging are both real.
     */
    private function seedSubscribedFeedWithEntries(User $user, string $titlePrefix, int $count): Subscription
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        $feed = new Feed('https://example.com/feed-' . uniqid('', true) . '.xml');
        $feed->setTitle('Seeded');
        $em->persist($feed);
        $sub = new Subscription($user, $feed, new \DateTimeImmutable('2026-07-01T00:00:00Z'));
        $em->persist($sub);

        for ($i = 1; $i <= $count; $i++) {
            $publishedAt = new \DateTimeImmutable(sprintf('2026-07-%02dT00:00:00Z', $i));
            $entry = new Entry(
                $feed,
                "$titlePrefix-$i",
                "https://example.com/$titlePrefix-$i",
                "$titlePrefix Post $i",
                new \DateTimeImmutable('2026-07-01T00:00:00Z'),
                $publishedAt,
            );
            $entry->setPublishedAt($publishedAt);
            $em->persist($entry);
        }
        $em->flush();

        return $sub;
    }

    /** A feed the given user has NOT subscribed to, with one matching entry. */
    private function seedUnsubscribedFeedWithEntry(User $owner, string $title): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        $feed = new Feed('https://example.com/stranger-' . uniqid('', true) . '.xml');
        $feed->setTitle('Stranger feed');
        $em->persist($feed);
        $em->persist(new Subscription($owner, $feed, new \DateTimeImmutable('2026-07-01T00:00:00Z')));

        $publishedAt = new \DateTimeImmutable('2026-07-01T00:00:00Z');
        $entry = new Entry($feed, 'stranger-1', 'https://example.com/stranger-1', $title, $publishedAt, $publishedAt);
        $entry->setPublishedAt($publishedAt);
        $em->persist($entry);
        $em->flush();
    }

    public function testMatchingEntryComesBackWithAnEntriesArray(): void
    {
        $client = self::createClient();
        [$headers, $user] = $this->auth('s-match@example.com');
        $this->seedSubscribedFeedWithEntries($user, 'Angular', 1);
        $this->seedSubscribedFeedWithEntries($user, 'Symfony', 1);

        $client->request('GET', '/api/entries/search?q=angular', server: $headers);

        self::assertResponseIsSuccessful();
        $body = $this->payload($client);
        self::assertIsArray($body['entries']);
        self::assertCount(1, $body['entries']);
        $first = $body['entries'][0];
        self::assertIsArray($first);
        self::assertSame('Angular Post 1', $first['title']);
    }

    public function testEntryInAFeedTheCallerDoesNotSubscribeToIsAbsent(): void
    {
        $client = self::createClient();
        [$headers] = $this->auth('s-idor@example.com');
        [, $stranger] = $this->auth('s-idor-owner@example.com');
        $this->seedUnsubscribedFeedWithEntry($stranger, 'Angular Exclusive');

        $client->request('GET', '/api/entries/search?q=angular', server: $headers);

        self::assertResponseIsSuccessful();
        $body = $this->payload($client);
        self::assertIsArray($body['entries']);
        self::assertCount(0, $body['entries']);
    }

    public function testTooShortQueryIsAValidationError(): void
    {
        $client = self::createClient();
        [$headers] = $this->auth('s-short@example.com');

        $client->request('GET', '/api/entries/search?q=ab', server: $headers);

        self::assertResponseStatusCodeSame(422);
        $body = $this->payload($client);
        self::assertSame('validation_error', $body['type']);
    }

    public function testUnknownParameterIsAValidationError(): void
    {
        $client = self::createClient();
        [$headers] = $this->auth('s-unknown@example.com');

        $client->request('GET', '/api/entries/search?q=angular&tag=3', server: $headers);

        self::assertResponseStatusCodeSame(422);
        $body = $this->payload($client);
        self::assertSame('validation_error', $body['type']);
    }

    public function testMalformedCursorIsAValidationError(): void
    {
        $client = self::createClient();
        [$headers] = $this->auth('s-cursor@example.com');

        $client->request('GET', '/api/entries/search?q=angular&cursor=not-a-cursor', server: $headers);

        self::assertResponseStatusCodeSame(422);
        $body = $this->payload($client);
        self::assertSame('validation_error', $body['type']);
    }

    public function testMissingBearerTokenIsRejected(): void
    {
        $client = self::createClient();

        $client->request('GET', '/api/entries/search?q=angular');

        self::assertResponseStatusCodeSame(401);
    }

    public function testAFullPagePaginatesWithoutSkippingOrRepeating(): void
    {
        $client = self::createClient();
        [$headers, $user] = $this->auth('s-page@example.com');
        $this->seedSubscribedFeedWithEntries($user, 'Angular', 3);

        $client->request('GET', '/api/entries/search?q=angular&limit=2', server: $headers);
        self::assertResponseIsSuccessful();
        $page1 = $this->payload($client);
        self::assertIsArray($page1['entries']);
        self::assertCount(2, $page1['entries']);
        self::assertIsString($page1['nextCursor']);
        // Newest first: "Angular Post 3" then "Angular Post 2".
        [$firstOfPage1, $secondOfPage1] = $page1['entries'];
        self::assertIsArray($firstOfPage1);
        self::assertIsArray($secondOfPage1);
        self::assertSame('Angular Post 3', $firstOfPage1['title']);
        self::assertSame('Angular Post 2', $secondOfPage1['title']);

        $client->request(
            'GET',
            '/api/entries/search?q=angular&limit=2&cursor=' . urlencode($page1['nextCursor']),
            server: $headers,
        );
        self::assertResponseIsSuccessful();
        $page2 = $this->payload($client);
        self::assertIsArray($page2['entries']);
        self::assertCount(1, $page2['entries']);
        $firstOfPage2 = $page2['entries'][0];
        self::assertIsArray($firstOfPage2);
        self::assertSame('Angular Post 1', $firstOfPage2['title']);
        self::assertNull($page2['nextCursor']);
    }
}
