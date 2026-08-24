<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Entity\Entry;
use App\Entity\Feed;
use App\Entity\SavedSearch;
use App\Entity\Subscription;
use App\Entity\User;
use App\Tests\Support\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class SavedSearchControllerTest extends WebTestCase
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
    private function authHeaderFor(User $user): array
    {
        $tokens = self::getContainer()->get(JWTTokenManagerInterface::class);
        self::assertInstanceOf(JWTTokenManagerInterface::class, $tokens);

        return [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $tokens->create($user),
            'CONTENT_TYPE' => 'application/json',
        ];
    }

    public function testAnonymousIsRejected(): void
    {
        $client = self::createClient();
        $client->request('GET', '/api/saved-searches');
        self::assertResponseStatusCodeSame(401);
    }

    public function testCreateListWithUnreadCountAndDelete(): void
    {
        $client = self::createClient();
        $user = $this->userFactory()->create('saver@example.com');
        $headers = $this->authHeaderFor($user);

        // Seed a subscribed feed with one unread matching entry.
        $em = $this->em();
        $feed = new Feed('https://example.com/f.xml');
        $feed->setTitle('Example');
        $em->persist($feed);
        $em->persist(new Subscription($user, $feed, new \DateTimeImmutable('2026-07-01T00:00:00Z')));
        $entry = new Entry(
            $feed,
            'g1',
            'https://example.com/g1',
            'A punk revival',
            new \DateTimeImmutable('2026-07-01T00:00:00Z'),
            new \DateTimeImmutable('2026-07-10T00:00:00Z'),
        );
        $em->persist($entry);
        $em->flush();

        // Create a whole-word saved search.
        $client->request(
            'POST',
            '/api/saved-searches',
            server: $headers,
            content: json_encode(['term' => 'punk', 'wholeWord' => true], \JSON_THROW_ON_ERROR),
        );
        self::assertResponseStatusCodeSame(201);
        $created = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($created);
        self::assertIsArray($created['savedSearch']);
        self::assertSame('punk', $created['savedSearch']['term']);
        self::assertTrue($created['savedSearch']['wholeWord']);
        $savedId = $created['savedSearch']['id'];
        self::assertIsInt($savedId);

        // Duplicate create is idempotent (200, same id).
        $client->request(
            'POST',
            '/api/saved-searches',
            server: $headers,
            content: json_encode(['term' => 'punk', 'wholeWord' => true], \JSON_THROW_ON_ERROR),
        );
        self::assertResponseStatusCodeSame(200);
        $again = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($again);
        self::assertIsArray($again['savedSearch']);
        self::assertSame($savedId, $again['savedSearch']['id']);

        // List carries the live unread-match count (whole-word "punk" matches the one unread entry).
        $client->request('GET', '/api/saved-searches', server: $headers);
        self::assertResponseIsSuccessful();
        $list = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($list);
        self::assertIsArray($list['savedSearches']);
        self::assertCount(1, $list['savedSearches']);
        self::assertIsArray($list['savedSearches'][0]);
        self::assertSame('punk', $list['savedSearches'][0]['term']);
        self::assertSame(1, $list['savedSearches'][0]['unreadCount']);

        // Delete.
        $client->request('DELETE', '/api/saved-searches/' . $savedId, server: $headers);
        self::assertResponseStatusCodeSame(204);

        $client->request('GET', '/api/saved-searches', server: $headers);
        $empty = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($empty);
        self::assertIsArray($empty['savedSearches']);
        self::assertCount(0, $empty['savedSearches']);
    }

    public function testValidationRejectsShortTerm(): void
    {
        $client = self::createClient();
        $headers = $this->authHeaderFor($this->userFactory()->create('shorty@example.com'));
        $client->request(
            'POST',
            '/api/saved-searches',
            server: $headers,
            content: json_encode(['term' => 'ab', 'wholeWord' => false], \JSON_THROW_ON_ERROR),
        );
        self::assertResponseStatusCodeSame(422);
    }

    public function testDeleteAnotherUsersSavedSearchIs404(): void
    {
        $client = self::createClient();
        $em = $this->em();
        $owner = $this->userFactory()->create('owner3@example.com');
        $saved = new SavedSearch($owner, 'private', false);
        $em->persist($saved);
        $em->flush();

        $headers = $this->authHeaderFor($this->userFactory()->create('intruder3@example.com'));
        $client->request('DELETE', '/api/saved-searches/' . $saved->getId(), server: $headers);
        self::assertResponseStatusCodeSame(404); // not 403 — do not reveal existence
    }
}
