<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Entity\Tag;
use App\Entity\User;
use App\Tests\Support\ApiTestCase;
use App\Tests\Support\NetworkFeedSubscriptions;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpFoundation\Response;

final class EntryTagScopeTest extends ApiTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
    }

    public function testATopTagListsAndMarksReadItsGrandchildsEntries(): void
    {
        [$headers, $user] = $this->auth('tag-grandchild@example.com');
        $reading = $this->tag($user, 'reading', null);
        $longReads = $this->tag($user, 'long reads', $reading);
        $essays = $this->tag($user, 'essays', $longReads);
        $this->taggedFeed($user, 'own.example', 'essay', [$essays], '2026-10-04T00:00:00Z');
        $this->entityManager()->flush();

        $this->client->request('GET', '/api/entries?tag=' . $reading->requireId(), server: $headers);
        self::assertSame(['own.example essay'], $this->titlesOfResponse());
        $this->client->request('GET', '/api/entries?tag=' . $longReads->requireId(), server: $headers);
        self::assertSame(['own.example essay'], $this->titlesOfResponse());

        $this->markRead($headers, $reading->requireId());
        $this->client->request('GET', '/api/entries?tag=' . $essays->requireId() . '&view=unread', server: $headers);
        self::assertSame([], $this->titlesOfResponse());
    }

    public function testAParentListsItsOwnAndItsChildrensEntriesEachOnce(): void
    {
        [$headers, $user] = $this->auth('tag-parent@example.com');
        $tagIds = $this->seedHierarchy($user, 'own.example');

        $this->client->request('GET', '/api/entries?tag=' . $tagIds['reading'], server: $headers);

        self::assertResponseIsSuccessful();
        self::assertSame(['own.example both', 'own.example child', 'own.example parent'], $this->titlesOfResponse());
    }

    public function testAChildListsOnlyItsOwnEntries(): void
    {
        [$headers, $user] = $this->auth('tag-child@example.com');
        $tagIds = $this->seedHierarchy($user, 'own.example');

        $this->client->request('GET', '/api/entries?tag=' . $tagIds['long reads'], server: $headers);

        self::assertSame(['own.example both', 'own.example child'], $this->titlesOfResponse());
    }

    public function testAParentNeverListsAnotherUsersFeeds(): void
    {
        [$headers, $user] = $this->auth('tag-own@example.com');
        $tagIds = $this->seedHierarchy($user, 'own.example');
        [, $stranger] = $this->auth('tag-stranger@example.com');
        $this->seedHierarchy($stranger, 'stranger.example');
        $this->seedStrangerChildOf($stranger, $tagIds['reading']);

        $this->client->request('GET', '/api/entries?tag=' . $tagIds['reading'], server: $headers);

        self::assertSame(['own.example both', 'own.example child', 'own.example parent'], $this->titlesOfResponse());
    }

    public function testMarkingAParentReadReachesItsChildrensFeeds(): void
    {
        [$headers, $user] = $this->auth('tag-mark-parent@example.com');
        $tagIds = $this->seedHierarchy($user, 'own.example');

        $this->markRead($headers, $tagIds['reading']);
        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);

        $this->client->request('GET', '/api/entries?tag=' . $tagIds['reading'] . '&view=unread', server: $headers);
        self::assertSame([], $this->titlesOfResponse());
        $this->client->request('GET', '/api/entries?tag=' . $tagIds['tech'] . '&view=unread', server: $headers);
        self::assertSame(['own.example tech'], $this->titlesOfResponse());
    }

    public function testMarkingAChildReadLeavesTheParentsOwnFeedUnread(): void
    {
        [$headers, $user] = $this->auth('tag-mark-child@example.com');
        $tagIds = $this->seedHierarchy($user, 'own.example');

        $this->markRead($headers, $tagIds['long reads']);

        $this->client->request('GET', '/api/entries?tag=' . $tagIds['reading'] . '&view=unread', server: $headers);
        self::assertSame(['own.example parent'], $this->titlesOfResponse());
    }

    /** @param array<string, string> $headers */
    private function markRead(array $headers, int $tagId): void
    {
        $this->client->request(
            'POST',
            '/api/entries/mark-read',
            server: $headers + ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(
                ['scope' => 'tag', 'id' => $tagId, 'until' => '2026-10-12T00:00:00Z'],
                \JSON_THROW_ON_ERROR,
            ),
        );
    }

    /**
     * reading (root): parent.xml, both.xml. long reads (child of reading): child.xml, both.xml. tech (root): tech.xml.
     * Every feed lives on `$host` and every post is titled "<host> <feed>", so two users never share a feed row.
     *
     * @return array<string, int> tag id by name
     */
    private function seedHierarchy(User $user, string $host): array
    {
        $reading = $this->tag($user, 'reading', null);
        $longReads = $this->tag($user, 'long reads', $reading);
        $tech = $this->tag($user, 'tech', null);

        $this->taggedFeed($user, $host, 'parent', [$reading], '2026-10-04T00:00:00Z');
        $this->taggedFeed($user, $host, 'child', [$longReads], '2026-10-05T00:00:00Z');
        $this->taggedFeed($user, $host, 'both', [$reading, $longReads], '2026-10-06T00:00:00Z');
        $this->taggedFeed($user, $host, 'tech', [$tech], '2026-10-07T00:00:00Z');
        $this->entityManager()->flush();

        return [
            'reading' => $reading->requireId(),
            'long reads' => $longReads->requireId(),
            'tech' => $tech->requireId(),
        ];
    }

    /** The API refuses a parent owned by another user, so only a direct write can build this. */
    private function seedStrangerChildOf(User $stranger, int $parentTagId): void
    {
        $parent = $this->entityManager()->find(Tag::class, $parentTagId);
        self::assertInstanceOf(Tag::class, $parent);
        $adopted = $this->tag($stranger, 'adopted', $parent);
        $this->taggedFeed($stranger, 'stranger.example', 'adopted', [$adopted], '2026-10-08T00:00:00Z');
        $this->entityManager()->flush();
    }

    /** @param list<Tag> $tags */
    private function taggedFeed(User $user, string $host, string $feedName, array $tags, string $publishedAt): void
    {
        $fixtures = new NetworkFeedSubscriptions($this->entityManager());
        $subscription = $fixtures->subscribe($user, \sprintf('https://%s/%s.xml', $host, $feedName));
        foreach ($tags as $tag) {
            $subscription->addTag($tag);
        }
        $fixtures->post($subscription, $host . ' ' . $feedName, $publishedAt);
    }

    private function tag(User $user, string $name, ?Tag $parent): Tag
    {
        $tag = new Tag($user, $name);
        $tag->setParent($parent);
        $this->entityManager()->persist($tag);
        $this->entityManager()->flush();

        return $tag;
    }

    /**
     * @return list<string>
     *
     * @phpstan-impure
     */
    private function titlesOfResponse(): array
    {
        $entries = $this->payload($this->client)['entries'];
        self::assertIsArray($entries);

        return array_map(static function (mixed $entry): string {
            self::assertIsArray($entry);
            self::assertIsString($entry['title']);

            return $entry['title'];
        }, array_values($entries));
    }

    /** @return array{0: array<string, string>, 1: User} */
    private function auth(string $email): array
    {
        $user = $this->factory()->create($email);
        $tokens = self::getContainer()->get(JWTTokenManagerInterface::class);
        self::assertInstanceOf(JWTTokenManagerInterface::class, $tokens);

        return [['HTTP_AUTHORIZATION' => 'Bearer ' . $tokens->create($user)], $user];
    }
}
