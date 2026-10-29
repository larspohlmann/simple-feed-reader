<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Entity\Feed;
use App\Entity\Subscription;
use App\Entity\Tag;
use App\Entity\User;
use App\Tests\Support\ApiTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpFoundation\Response;

final class TagControllerTest extends ApiTestCase
{
    /** @return array<string, string> */
    private function headersFor(User $user): array
    {
        return [...$this->bearerFor($user), 'CONTENT_TYPE' => 'application/json'];
    }

    public function testAnonymousIsRejected(): void
    {
        $client = self::createClient();
        $client->request('GET', '/api/tags');
        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testCreateListAndRejectDuplicateName(): void
    {
        $client = self::createClient();
        $headers = $this->headersFor($this->factory()->create('tagger@example.com'));

        $client->request(
            'POST',
            '/api/tags',
            server: $headers,
            content: json_encode(
                ['name' => 'News', 'color' => '#ff8800', 'icon' => 'newspaper'],
                \JSON_THROW_ON_ERROR,
            ),
        );
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
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
        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT);
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

    /** A new tag appends after the current maximum position, as SubscriptionTagPositions does, never at a fixed 0. */
    public function testCreatingASecondTagAppendsAfterTheFirst(): void
    {
        $client = self::createClient();
        $headers = $this->headersFor($this->factory()->create('tag-positions@example.com'));

        $client->request(
            'POST',
            '/api/tags',
            server: $headers,
            content: json_encode(['name' => 'First'], \JSON_THROW_ON_ERROR),
        );
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $first = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($first);
        self::assertIsArray($first['tag']);
        self::assertSame(0, $first['tag']['position']);

        $client->request(
            'POST',
            '/api/tags',
            server: $headers,
            content: json_encode(['name' => 'Second'], \JSON_THROW_ON_ERROR),
        );
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $second = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($second);
        self::assertIsArray($second['tag']);
        self::assertSame(1, $second['tag']['position']);
    }

    public function testInvalidColorIsRejected(): void
    {
        $client = self::createClient();
        $headers = $this->headersFor($this->factory()->create('badcolor@example.com'));

        $client->request(
            'POST',
            '/api/tags',
            server: $headers,
            content: json_encode(['name' => 'X', 'color' => 'red'], \JSON_THROW_ON_ERROR),
        );
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        $body = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($body);
        self::assertSame('validation_error', $body['type']);
    }

    public function testDeleteAnotherUsersTagIs404(): void
    {
        $client = self::createClient();
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        $factory = $this->factory();
        $owner = $factory->create('owner2@example.com');
        $tag = new Tag($owner, 'private');
        $entityManager->persist($tag);
        $entityManager->flush();

        $headers = $this->headersFor($this->factory()->create('intruder@example.com'));
        $client->request('DELETE', '/api/tags/' . $tag->getId(), server: $headers);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND); // not 403 — do not reveal existence
        self::assertStringContainsString('"detail":"No such tag."', (string) $client->getResponse()->getContent());
    }

    /** The tag row itself must be gone: the detach the next test asserts happens whether or not em->remove() ran. */
    public function testDeleteActuallyRemovesTheTagRow(): void
    {
        $client = self::createClient();
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        $user = $this->factory()->create('tag-row-gone@example.com');
        $tag = new Tag($user, 'Gone soon');
        $entityManager->persist($tag);
        $entityManager->flush();
        $tagId = $tag->requireId();

        $client->request('DELETE', '/api/tags/' . $tagId, server: $this->headersFor($user));

        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);
        $entityManager->clear();
        self::assertNull(
            $entityManager->getRepository(Tag::class)->find($tagId),
            'DELETE /api/tags/{id} must remove the tag row itself, not just its joins.',
        );
    }

    /** Deleting a tag detaches it from every subscription itself, relying on no FK cascade. */
    public function testDeleteDetachesTheTagFromEveryCarryingSubscription(): void
    {
        $client = self::createClient();
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        $user = $this->factory()->create('detacher@example.com');
        $tag = new Tag($user, 'Detach me');
        $entityManager->persist($tag);
        $feed = new Feed('https://detach.example/feed.xml');
        $entityManager->persist($feed);
        $subscription = new Subscription($user, $feed, new \DateTimeImmutable('2026-01-01T00:00:00Z'));
        $subscription->addTag($tag, 0);
        $entityManager->persist($subscription);
        $entityManager->flush();
        $subscriptionId = $subscription->requireId();

        $client->request('DELETE', '/api/tags/' . $tag->getId(), server: $this->headersFor($user));

        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);
        $entityManager->clear();
        $reloaded = $entityManager->getRepository(Subscription::class)->find($subscriptionId);
        self::assertInstanceOf(Subscription::class, $reloaded);
        self::assertCount(
            0,
            $reloaded->getTags(),
            'Deleting a tag must detach it from every subscription that carried it.',
        );
    }

    public function testACreatedChildEchoesItsParentAndTheListCarriesParentIds(): void
    {
        $client = self::createClient();
        $headers = $this->headersFor($this->factory()->create('tag-parent-wire@example.com'));

        $parentId = $this->createTag($client, $headers, ['name' => 'Reading']);
        $child = $this->createTagBody($client, $headers, ['name' => 'Long reads', 'parentId' => $parentId]);
        self::assertSame($parentId, $child['parentId']);

        $client->request('GET', '/api/tags', server: $headers);
        $list = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($list);
        self::assertIsArray($list['tags']);
        self::assertSame([null, $parentId], array_column($list['tags'], 'parentId'));
    }

    public function testAThirdLevelBelowTheTopIsAnInvalidParentProblem(): void
    {
        $client = self::createClient();
        $headers = $this->headersFor($this->factory()->create('tag-depth-wire@example.com'));
        $parentId = $this->createTag($client, $headers, ['name' => 'Reading']);
        $childId = $this->createTag($client, $headers, ['name' => 'Long reads', 'parentId' => $parentId]);
        $grandchildId = $this->createTag($client, $headers, ['name' => 'Essays', 'parentId' => $childId]);

        $client->request(
            'POST',
            '/api/tags',
            server: $headers,
            content: json_encode(['name' => 'Short essays', 'parentId' => $grandchildId], \JSON_THROW_ON_ERROR),
        );

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        $problem = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($problem);
        self::assertSame('invalid_tag_parent', $problem['type']);
        self::assertSame('Invalid parent tag', $problem['title']);
        self::assertSame('A tag can sit at most 2 levels below the top.', $problem['detail']);
        self::assertSame(['parentId' => ['A tag can sit at most 2 levels below the top.']], $problem['errors']);
    }

    public function testAnUpdateWithoutParentIdIsRefusedAndKeepsTheParent(): void
    {
        $client = self::createClient();
        $headers = $this->headersFor($this->factory()->create('tag-partial-patch@example.com'));
        $parentId = $this->createTag($client, $headers, ['name' => 'Reading']);
        $childId = $this->createTag($client, $headers, ['name' => 'Long reads', 'parentId' => $parentId]);

        $client->request(
            'PATCH',
            '/api/tags/' . $childId,
            server: $headers,
            content: json_encode(['name' => 'Long reads', 'color' => null, 'icon' => null], \JSON_THROW_ON_ERROR),
        );

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        $problem = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($problem);
        self::assertIsArray($problem['errors']);
        self::assertSame(['parentId'], array_keys($problem['errors']));
        $client->request('GET', '/api/tags', server: $headers);
        $list = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($list);
        self::assertIsArray($list['tags']);
        self::assertSame([null, $parentId], array_column($list['tags'], 'parentId'));
    }

    /**
     * @param array<string, string> $headers
     * @param array<string, mixed>  $body
     */
    private function createTag(KernelBrowser $client, array $headers, array $body): int
    {
        $created = $this->createTagBody($client, $headers, $body);
        self::assertIsInt($created['id']);

        return $created['id'];
    }

    /**
     * @param array<string, string> $headers
     * @param array<string, mixed>  $body
     *
     * @return array<mixed>
     */
    private function createTagBody(KernelBrowser $client, array $headers, array $body): array
    {
        $client->request('POST', '/api/tags', server: $headers, content: json_encode($body, \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $decoded = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        self::assertIsArray($decoded['tag']);

        return $decoded['tag'];
    }
}
