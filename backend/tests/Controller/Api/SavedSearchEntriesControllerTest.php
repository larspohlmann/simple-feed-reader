<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Entity\Entry;
use App\Entity\Feed;
use App\Entity\SavedSearch;
use App\Entity\Subscription;
use App\Entity\User;
use App\Tests\Support\ApiTestCase;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;

/**
 * The combined saved-search list and its mark-read.
 */
final class SavedSearchEntriesControllerTest extends ApiTestCase
{
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

    private function seedSubscribedFeed(User $user): Feed
    {
        $em = $this->em();
        $feed = new Feed('https://example.com/feed-' . uniqid('', true) . '.xml');
        $feed->setTitle('Seeded');
        $em->persist($feed);
        $em->persist(new Subscription($user, $feed, new \DateTimeImmutable('2026-07-01T00:00:00Z')));

        return $feed;
    }

    private function seedEntry(Feed $feed, string $title, \DateTimeImmutable $publishedAt): Entry
    {
        $entry = new Entry(
            $feed,
            'guid-' . uniqid('', true),
            'https://example.com/' . uniqid('', true),
            $title,
            $publishedAt,
            $publishedAt,
        );
        $entry->setPublishedAt($publishedAt);
        $this->em()->persist($entry);

        return $entry;
    }

    public function testListsMatchesOfEverySavedSearch(): void
    {
        $client = self::createClient();
        $user = $this->factory()->create('list-matches@example.com');
        $headers = $this->authHeaderFor($user);
        $feed = $this->seedSubscribedFeed($user);
        $this->seedEntry($feed, 'Climate report', new \DateTimeImmutable('2026-07-02T00:00:00Z'));
        $this->seedEntry($feed, 'Rocket launch', new \DateTimeImmutable('2026-07-01T00:00:00Z'));
        $this->seedEntry($feed, 'Nothing to see', new \DateTimeImmutable('2026-07-03T00:00:00Z'));
        $this->em()->persist(new SavedSearch($user, 'climate', false));
        $this->em()->persist(new SavedSearch($user, 'rocket', false));
        $this->em()->flush();

        $client->request('GET', '/api/entries/saved-searches', server: $headers);

        self::assertResponseIsSuccessful();
        $body = $this->payload($client);
        self::assertIsArray($body['entries']);
        $titles = array_column($body['entries'], 'title');
        self::assertSame(['Climate report', 'Rocket launch'], $titles);
    }

    public function testTheListSaysWhichSavedSearchMatchedEachEntry(): void
    {
        $client = self::createClient();
        $user = $this->factory()->create('matched-search@example.com');
        $headers = $this->authHeaderFor($user);
        $feed = $this->seedSubscribedFeed($user);
        $this->seedEntry($feed, 'Climate report', new \DateTimeImmutable('2026-07-02T00:00:00Z'));
        $this->seedEntry($feed, 'Rocket launch', new \DateTimeImmutable('2026-07-01T00:00:00Z'));
        $this->seedEntry($feed, 'Climate rocket', new \DateTimeImmutable('2026-07-03T00:00:00Z'));
        $climateSearch = new SavedSearch($user, 'climate', false);
        $this->em()->persist($climateSearch);
        $this->em()->flush();
        $rocketSearch = new SavedSearch($user, 'rocket', false);
        $this->em()->persist($rocketSearch);
        $this->em()->flush();

        $client->request('GET', '/api/entries/saved-searches', server: $headers);

        self::assertResponseIsSuccessful();
        $body = $this->payload($client);
        self::assertIsArray($body['entries']);
        self::assertIsArray($body['savedSearchIds']);
        $savedSearchIds = $body['savedSearchIds'];
        self::assertSame($climateSearch->getId(), $savedSearchIds[$this->entryIdByTitle($body, 'Climate report')]);
        self::assertSame($rocketSearch->getId(), $savedSearchIds[$this->entryIdByTitle($body, 'Rocket launch')]);
        // Matches both searches: the pill must name rocketSearch, the search
        // saved LAST (findForUser orders id DESC — the sidebar's own order),
        // proving the CASE branch order follows that order and not insertion.
        self::assertSame($rocketSearch->getId(), $savedSearchIds[$this->entryIdByTitle($body, 'Climate rocket')]);
    }

    /** @param array<string, mixed> $body */
    private function entryIdByTitle(array $body, string $title): int
    {
        self::assertIsArray($body['entries']);
        foreach ($body['entries'] as $entry) {
            self::assertIsArray($entry);
            if ($entry['title'] === $title) {
                self::assertIsInt($entry['id']);

                return $entry['id'];
            }
        }

        self::fail(\sprintf('No entry titled "%s" in the response.', $title));
    }

    public function testUnreadFlagNarrowsTheList(): void
    {
        $client = self::createClient();
        $user = $this->factory()->create('unread-narrows@example.com');
        $headers = $this->authHeaderFor($user);
        $feed = $this->seedSubscribedFeed($user);
        $read = $this->seedEntry($feed, 'Climate read', new \DateTimeImmutable('2026-07-01T00:00:00Z'));
        $this->seedEntry($feed, 'Climate unread', new \DateTimeImmutable('2026-07-02T00:00:00Z'));
        $this->em()->persist(new SavedSearch($user, 'climate', false));
        $this->em()->flush();

        $client->request(
            'PATCH',
            '/api/entries/' . $read->getId() . '/state',
            server: $headers,
            content: json_encode(['isHidden' => true], \JSON_THROW_ON_ERROR),
        );
        self::assertResponseIsSuccessful();

        $client->request('GET', '/api/entries/saved-searches?unread=1', server: $headers);

        self::assertResponseIsSuccessful();
        $body = $this->payload($client);
        self::assertIsArray($body['entries']);
        self::assertCount(1, $body['entries']);
        $first = $body['entries'][0];
        self::assertIsArray($first);
        self::assertSame('Climate unread', $first['title']);
    }

    public function testWithoutSavedSearchesTheListIsEmpty(): void
    {
        $client = self::createClient();
        $user = $this->factory()->create('no-searches@example.com');
        $headers = $this->authHeaderFor($user);
        $feed = $this->seedSubscribedFeed($user);
        $this->seedEntry($feed, 'Climate report', new \DateTimeImmutable('2026-07-01T00:00:00Z'));
        $this->em()->flush();

        $client->request('GET', '/api/entries/saved-searches', server: $headers);

        self::assertResponseIsSuccessful();
        $body = $this->payload($client);
        self::assertSame([], $body['entries']);
        // Must serialize as a JSON object even when empty: a bare `[]` is not
        // the {entryId: searchId} map a client decodes.
        self::assertStringContainsString('"savedSearchIds":{}', (string) $client->getResponse()->getContent());
    }

    public function testMarkReadFlipsMatchesUpToTheWatermarkOnly(): void
    {
        $client = self::createClient();
        $user = $this->factory()->create('mark-read-watermark@example.com');
        $headers = $this->authHeaderFor($user);
        $feed = $this->seedSubscribedFeed($user);
        $this->seedEntry($feed, 'Climate old', new \DateTimeImmutable('2026-07-05T00:00:00Z'));
        $this->seedEntry($feed, 'Climate new', new \DateTimeImmutable('2026-07-15T00:00:00Z'));
        $this->em()->persist(new SavedSearch($user, 'climate', false));
        $this->em()->flush();

        $client->request(
            'POST',
            '/api/entries/saved-searches/mark-read',
            server: $headers,
            content: json_encode(['until' => '2026-07-10T00:00:00+00:00'], \JSON_THROW_ON_ERROR),
        );

        self::assertResponseStatusCodeSame(204);

        $client->request('GET', '/api/entries/saved-searches?unread=1', server: $headers);
        $body = $this->payload($client);
        self::assertIsArray($body['entries']);
        $titles = array_column($body['entries'], 'title');
        self::assertSame(['Climate new'], $titles);
    }

    public function testAnotherUsersSavedSearchesAreNotUsed(): void
    {
        $client = self::createClient();
        $user = $this->factory()->create('idor-victim@example.com');
        $stranger = $this->factory()->create('idor-stranger@example.com');
        $headers = $this->authHeaderFor($user);
        $feed = $this->seedSubscribedFeed($user);
        $this->seedEntry($feed, 'Climate report', new \DateTimeImmutable('2026-07-01T00:00:00Z'));
        $this->em()->persist(new SavedSearch($stranger, 'climate', false));
        $this->em()->flush();

        $client->request('GET', '/api/entries/saved-searches', server: $headers);

        self::assertResponseIsSuccessful();
        $body = $this->payload($client);
        self::assertSame([], $body['entries']);
    }
}
