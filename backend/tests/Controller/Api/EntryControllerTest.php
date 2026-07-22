<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Entity\Entry;
use App\Entity\Feed;
use App\Entity\Subscription;
use App\Entity\User;
use App\Tests\Support\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class EntryControllerTest extends WebTestCase
{
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

    private function seedFeedWithEntries(User $user, int $count): Subscription
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        $feed = new Feed('https://example.com/feed-' . uniqid('', true) . '.xml');
        $feed->setTitle('Seeded');
        $em->persist($feed);
        $sub = new Subscription($user, $feed, new \DateTimeImmutable('2026-07-01T00:00:00Z'));
        $em->persist($sub);

        for ($i = 1; $i <= $count; $i++) {
            $e = new Entry(
                $feed,
                "g$i",
                "https://example.com/$i",
                "Post $i",
                new \DateTimeImmutable('2026-07-01T00:00:00Z'),
            );
            $e->setPublishedAt(new \DateTimeImmutable(sprintf('2026-07-%02dT00:00:00Z', $i)));
            $em->persist($e);
        }
        $em->flush();

        return $sub;
    }

    public function testAnonymousIsRejected(): void
    {
        $client = self::createClient();
        $client->request('GET', '/api/entries');
        self::assertResponseStatusCodeSame(401);
    }

    public function testListsNewestFirstWithState(): void
    {
        $client = self::createClient();
        [$headers, $user] = $this->auth('e-list@example.com');
        $this->seedFeedWithEntries($user, 3);

        $client->request('GET', '/api/entries', server: $headers);
        self::assertResponseIsSuccessful();
        $body = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($body);
        self::assertIsArray($body['entries']);
        self::assertCount(3, $body['entries']);
        $first = $body['entries'][0];
        self::assertIsArray($first);
        self::assertSame('Post 3', $first['title']);
        self::assertFalse($first['isRead']);
        self::assertSame('Seeded', $first['source']);
        self::assertArrayHasKey('nextCursor', $body);
        self::assertNull($body['nextCursor']);
    }

    public function testPaginatesWithCursor(): void
    {
        $client = self::createClient();
        [$headers, $user] = $this->auth('e-page@example.com');
        $this->seedFeedWithEntries($user, 3);

        $client->request('GET', '/api/entries?limit=2', server: $headers);
        $page1 = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($page1);
        self::assertIsArray($page1['entries']);
        self::assertCount(2, $page1['entries']);
        self::assertIsString($page1['nextCursor']);

        $client->request(
            'GET',
            '/api/entries?limit=2&cursor=' . urlencode($page1['nextCursor']),
            server: $headers,
        );
        $page2 = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($page2);
        self::assertIsArray($page2['entries']);
        self::assertCount(1, $page2['entries']);
        $firstOfPage2 = $page2['entries'][0];
        self::assertIsArray($firstOfPage2);
        self::assertSame('Post 1', $firstOfPage2['title']);
        self::assertNull($page2['nextCursor']);
    }

    public function testRejectsUnknownView(): void
    {
        $client = self::createClient();
        [$headers] = $this->auth('e-view@example.com');

        $client->request('GET', '/api/entries?view=bogus', server: $headers);
        self::assertResponseStatusCodeSame(422);
        $body = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($body);
        self::assertSame('validation_error', $body['type']); // uniform with every other invalid field
    }

    public function testInvalidCursorIsRejected(): void
    {
        $client = self::createClient();
        [$headers] = $this->auth('e-cursor@example.com');

        $client->request('GET', '/api/entries?cursor=not-a-cursor', server: $headers);
        self::assertResponseStatusCodeSame(422);
        $body = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($body);
        self::assertSame('validation_error', $body['type']);
        self::assertIsArray($body['errors']);
        self::assertArrayHasKey('cursor', $body['errors']);
    }

    public function testPatchStateLazilyCreatesAndReturnsState(): void
    {
        $client = self::createClient();
        [$headers, $user] = $this->auth('e-patch@example.com');
        $sub = $this->seedFeedWithEntries($user, 1);
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $entryId = $em->getRepository(Entry::class)->findOneBy(['feed' => $sub->getFeed()])?->getId();
        self::assertNotNull($entryId);

        $client->request(
            'PATCH',
            "/api/entries/$entryId/state",
            server: $headers + ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['isRead' => true, 'isFavorite' => true], \JSON_THROW_ON_ERROR),
        );
        self::assertResponseIsSuccessful();
        $body = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($body);
        self::assertIsArray($body['state']);
        self::assertTrue($body['state']['isRead']);
        self::assertTrue($body['state']['isFavorite']);
        self::assertFalse($body['state']['isKept']);
        self::assertNotNull($body['state']['readAt']);
    }

    public function testPatchStateUnreadClearsReadAt(): void
    {
        $client = self::createClient();
        [$headers, $user] = $this->auth('e-unread@example.com');
        $sub = $this->seedFeedWithEntries($user, 1);
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $entryId = $em->getRepository(Entry::class)->findOneBy(['feed' => $sub->getFeed()])?->getId();
        self::assertNotNull($entryId);

        $client->request(
            'PATCH',
            "/api/entries/$entryId/state",
            server: $headers + ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['isRead' => true], \JSON_THROW_ON_ERROR),
        );
        $client->request(
            'PATCH',
            "/api/entries/$entryId/state",
            server: $headers + ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['isRead' => false], \JSON_THROW_ON_ERROR),
        );
        $body = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($body);
        self::assertIsArray($body['state']);
        self::assertFalse($body['state']['isRead']);
        self::assertNull($body['state']['readAt']);
    }

    public function testCannotPatchEntryOfUnsubscribedFeed(): void
    {
        $client = self::createClient();
        [$headers] = $this->auth('e-idor@example.com');
        [, $stranger] = $this->auth('e-owner@example.com');
        $strangerSub = $this->seedFeedWithEntries($stranger, 1);
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $entryId = $em->getRepository(Entry::class)->findOneBy(['feed' => $strangerSub->getFeed()])?->getId();
        self::assertNotNull($entryId);

        $client->request(
            'PATCH',
            "/api/entries/$entryId/state",
            server: $headers + ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['isRead' => true], \JSON_THROW_ON_ERROR),
        );
        self::assertResponseStatusCodeSame(404);
    }

    public function testMarkReadAllThenListUnreadIsEmpty(): void
    {
        $client = self::createClient();
        [$headers, $user] = $this->auth('e-markread@example.com');
        $this->seedFeedWithEntries($user, 3);

        $client->request(
            'POST',
            '/api/entries/mark-read',
            server: $headers + ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['scope' => 'all', 'until' => '2026-08-01T00:00:00Z'], \JSON_THROW_ON_ERROR),
        );
        self::assertResponseStatusCodeSame(204);

        $client->request('GET', '/api/entries?view=unread', server: $headers);
        $body = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($body);
        self::assertIsArray($body['entries']);
        self::assertCount(0, $body['entries']);
    }

    public function testMarkReadRejectsBadTimestamp(): void
    {
        $client = self::createClient();
        [$headers] = $this->auth('e-markbad@example.com');
        $client->request(
            'POST',
            '/api/entries/mark-read',
            server: $headers + ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['scope' => 'all', 'until' => 'nonsense'], \JSON_THROW_ON_ERROR),
        );
        self::assertResponseStatusCodeSame(422);
    }
}
