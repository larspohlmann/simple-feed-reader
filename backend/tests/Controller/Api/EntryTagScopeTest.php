<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Entity\Tag;
use App\Entity\User;
use App\Tests\Support\ApiTestCase;
use App\Tests\Support\NetworkFeedSubscriptions;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

final class EntryTagScopeTest extends ApiTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
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

        $this->client->request('GET', '/api/entries?tag=' . $tagIds['reading'], server: $headers);

        self::assertSame(['own.example both', 'own.example child', 'own.example parent'], $this->titlesOfResponse());
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

    /** @return list<string> */
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
