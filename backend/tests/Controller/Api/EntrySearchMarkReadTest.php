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

final class EntrySearchMarkReadTest extends WebTestCase
{
    private function em(): EntityManagerInterface
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        return $em;
    }

    private function userFactory(): UserFactory
    {
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        self::assertInstanceOf(UserPasswordHasherInterface::class, $hasher);

        return new UserFactory($this->em(), $hasher);
    }

    /** @return array<string, string> */
    private function authHeader(User $user): array
    {
        $tokens = self::getContainer()->get(JWTTokenManagerInterface::class);
        self::assertInstanceOf(JWTTokenManagerInterface::class, $tokens);

        return [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $tokens->create($user),
            'CONTENT_TYPE' => 'application/json',
        ];
    }

    private function seedSubscribedMatchingEntry(User $user): void
    {
        $em = $this->em();

        $feed = new Feed('https://example.com/feed.xml');
        $feed->setTitle('Example');
        $em->persist($feed);
        $em->persist(new Subscription($user, $feed, new \DateTimeImmutable('2026-07-01T00:00:00Z')));

        $entry = new Entry(
            $feed,
            'guid-1',
            'https://example.com/guid-1',
            'Klima report',
            new \DateTimeImmutable('2026-07-01T00:00:00Z'),
            new \DateTimeImmutable('2026-07-10T00:00:00Z'),
        );
        $em->persist($entry);
        $em->flush();
    }

    public function testAnonymousIsRejected(): void
    {
        $client = self::createClient();
        $client->request(
            'POST',
            '/api/entries/search/mark-read',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['q' => 'klima', 'until' => '2100-01-01T00:00:00+00:00'], \JSON_THROW_ON_ERROR),
        );
        self::assertResponseStatusCodeSame(401);
    }

    public function testTooShortQueryIsRejected(): void
    {
        $client = self::createClient();
        $user = $this->userFactory()->create('short-query@example.com');
        $headers = $this->authHeader($user);

        $client->request(
            'POST',
            '/api/entries/search/mark-read',
            server: $headers,
            content: json_encode(['q' => 'ab', 'until' => '2100-01-01T00:00:00+00:00'], \JSON_THROW_ON_ERROR),
        );
        self::assertResponseStatusCodeSame(422);
        $body = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($body);
        self::assertSame('validation_error', $body['type']);
    }

    public function testHundredCharWholeWordTermIsAccepted(): void
    {
        // The whole-word request raw `q` carries a trailing space
        // (`term . ' '`), so a 100-char term sends 101 raw characters. The
        // trimmed length SearchTerms::fromInput() enforces stays within
        // bounds; a redundant Length constraint on this DTO used to reject
        // it at 101 (#581 off-by-one).
        $client = self::createClient();
        $user = $this->userFactory()->create('hundred-char-term@example.com');
        $headers = $this->authHeader($user);

        $client->request(
            'POST',
            '/api/entries/search/mark-read',
            server: $headers,
            content: json_encode(
                ['q' => str_repeat('a', 100) . ' ', 'until' => '2100-01-01T00:00:00+00:00'],
                \JSON_THROW_ON_ERROR,
            ),
        );
        self::assertResponseStatusCodeSame(204);
    }

    public function testMarksAllMatchingEntriesRead(): void
    {
        $client = self::createClient();
        $user = $this->userFactory()->create('search-mark-read@example.com');
        $this->seedSubscribedMatchingEntry($user);
        $headers = $this->authHeader($user);

        $client->request(
            'POST',
            '/api/entries/search/mark-read',
            server: $headers,
            content: json_encode(['q' => 'klima', 'until' => '2100-01-01T00:00:00+00:00'], \JSON_THROW_ON_ERROR),
        );
        self::assertResponseStatusCodeSame(204);

        $client->request('GET', '/api/entries/search?q=klima', server: $headers);
        self::assertResponseIsSuccessful();
        $page = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($page);
        self::assertIsArray($page['entries']);
        self::assertCount(1, $page['entries']);
        self::assertIsArray($page['entries'][0]);
        self::assertTrue($page['entries'][0]['isRead']);
    }
}
