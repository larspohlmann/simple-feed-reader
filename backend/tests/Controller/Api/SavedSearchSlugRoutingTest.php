<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Entity\Entry;
use App\Entity\Feed;
use App\Entity\SavedSearch;
use App\Entity\SavedSearchEntry;
use App\Entity\Subscription;
use App\Entity\User;
use App\Tests\Support\ApiTestCase;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;

final class SavedSearchSlugRoutingTest extends ApiTestCase
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

    public function testCreateReturnsAnIdPrefixedSlug(): void
    {
        $client = self::createClient();
        $headers = $this->authHeaderFor($this->factory()->create('slug-create@example.com'));

        $client->request(
            'POST',
            '/api/saved-searches',
            server: $headers,
            content: json_encode([
                'term' => 'Climate News',
                'wholeWord' => false,
                'phrase' => false,
            ], \JSON_THROW_ON_ERROR),
        );

        self::assertResponseStatusCodeSame(201);
        $created = $this->payload($client);
        self::assertIsArray($created['savedSearch']);
        $savedId = $created['savedSearch']['id'];
        self::assertIsInt($savedId);
        self::assertSame($savedId . '-climate-news', $created['savedSearch']['slug']);
    }

    public function testSingleSavedSearchListsOnlyItsMembers(): void
    {
        $client = self::createClient();
        $user = $this->factory()->create('single-search-members@example.com');
        $headers = $this->authHeaderFor($user);
        $feed = new Feed('https://example.com/single-search-feed.xml');
        $this->em()->persist($feed);
        $this->em()->persist(new Subscription($user, $feed, new \DateTimeImmutable('2026-07-01T00:00:00Z')));
        $entry = new Entry(
            $feed,
            'single-search-guid',
            'https://example.com/single-search-entry',
            'Climate report',
            new \DateTimeImmutable('2026-07-02T00:00:00Z'),
            new \DateTimeImmutable('2026-07-02T00:00:00Z'),
        );
        $this->em()->persist($entry);
        $search = new SavedSearch($user, 'climate', false);
        $this->em()->persist($search);
        $this->em()->flush();
        $this->em()->persist(new SavedSearchEntry($search, $entry, new \DateTimeImmutable('2026-09-22T10:00:00')));
        $this->em()->flush();

        $client->request('GET', '/api/entries/saved-searches/' . $search->getId(), server: $headers);

        self::assertResponseIsSuccessful();
        $body = $this->payload($client);
        self::assertIsArray($body['entries']);
        self::assertArrayHasKey('nextCursor', $body);
        self::assertSame(['Climate report'], array_column($body['entries'], 'title'));
    }

    public function testSingleSavedSearchIsNotFoundForAnotherUsersSearch(): void
    {
        $client = self::createClient();
        $user = $this->factory()->create('single-search-owner@example.com');
        $stranger = $this->factory()->create('single-search-stranger@example.com');
        $headers = $this->authHeaderFor($user);
        $strangerSearch = new SavedSearch($stranger, 'sports', false);
        $this->em()->persist($strangerSearch);
        $this->em()->flush();

        $client->request('GET', '/api/entries/saved-searches/' . $strangerSearch->getId(), server: $headers);

        self::assertResponseStatusCodeSame(404);
    }
}
