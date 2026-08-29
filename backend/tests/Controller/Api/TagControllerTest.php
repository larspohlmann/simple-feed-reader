<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Entity\Feed;
use App\Entity\Subscription;
use App\Entity\Tag;
use App\Entity\User;
use App\Tests\Support\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class TagControllerTest extends WebTestCase
{
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

        return [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $tokens->create($user),
            'CONTENT_TYPE' => 'application/json',
        ];
    }

    /** @return array<string, string> */
    private function headersFor(User $user): array
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
        $client->request('GET', '/api/tags');
        self::assertResponseStatusCodeSame(401);
    }

    public function testCreateListAndRejectDuplicateName(): void
    {
        $client = self::createClient();
        $headers = $this->authHeader('tagger@example.com');

        $client->request(
            'POST',
            '/api/tags',
            server: $headers,
            content: json_encode(
                ['name' => 'News', 'color' => '#ff8800', 'icon' => 'newspaper'],
                \JSON_THROW_ON_ERROR,
            ),
        );
        self::assertResponseStatusCodeSame(201);
        $created = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($created);
        self::assertIsArray($created['tag']);
        self::assertSame('News', $created['tag']['name']);
        self::assertSame('#ff8800', $created['tag']['color']);
        self::assertSame('newspaper', $created['tag']['icon']);

        // Case-insensitive duplicate is rejected with a domain 409.
        $client->request(
            'POST',
            '/api/tags',
            server: $headers,
            content: json_encode(['name' => 'news'], \JSON_THROW_ON_ERROR),
        );
        self::assertResponseStatusCodeSame(409);
        $conflict = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($conflict);
        self::assertSame('tag_name_taken', $conflict['type']);

        $client->request('GET', '/api/tags', server: $headers);
        self::assertResponseIsSuccessful();
        $list = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($list);
        self::assertIsArray($list['tags']);
        self::assertCount(1, $list['tags']);
    }

    public function testInvalidColorIsRejected(): void
    {
        $client = self::createClient();
        $headers = $this->authHeader('badcolor@example.com');

        $client->request(
            'POST',
            '/api/tags',
            server: $headers,
            content: json_encode(['name' => 'X', 'color' => 'red'], \JSON_THROW_ON_ERROR),
        );
        self::assertResponseStatusCodeSame(422);
        $body = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($body);
        self::assertSame('validation_error', $body['type']);
    }

    public function testDeleteAnotherUsersTagIs404(): void
    {
        $client = self::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        $factory = $this->userFactory();
        $owner = $factory->create('owner2@example.com');
        $tag = new Tag($owner, 'private');
        $em->persist($tag);
        $em->flush();

        $headers = $this->authHeader('intruder@example.com');
        $client->request('DELETE', '/api/tags/' . $tag->getId(), server: $headers);
        self::assertResponseStatusCodeSame(404); // not 403 — do not reveal existence
    }

    /**
     * Deleting a tag must detach it from every subscription that carried it
     * first (portable across SQLite/MySQL — no FK cascade relied on). This
     * pins the substitution of findForUserByTagId(userId, tagId) for the
     * former findByTag(Tag): same set of subscriptions, resolved by the
     * tag's owner instead of the tag entity itself.
     */
    public function testDeleteDetachesTheTagFromEveryCarryingSubscription(): void
    {
        $client = self::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        $user = $this->userFactory()->create('detacher@example.com');
        $tag = new Tag($user, 'Detach me');
        $em->persist($tag);
        $feed = new Feed('https://detach.example/feed.xml');
        $em->persist($feed);
        $subscription = new Subscription($user, $feed, new \DateTimeImmutable('2026-01-01T00:00:00Z'));
        $subscription->addTag($tag, 0);
        $em->persist($subscription);
        $em->flush();
        $subscriptionId = (int) $subscription->getId();

        $client->request('DELETE', '/api/tags/' . $tag->getId(), server: $this->headersFor($user));

        self::assertResponseStatusCodeSame(204);
        $em->clear();
        $reloaded = $em->getRepository(Subscription::class)->find($subscriptionId);
        self::assertInstanceOf(Subscription::class, $reloaded);
        self::assertCount(
            0,
            $reloaded->getTags(),
            'Deleting a tag must detach it from every subscription that carried it.',
        );
    }
}
