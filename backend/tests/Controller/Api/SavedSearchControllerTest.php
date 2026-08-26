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

final class SavedSearchControllerTest extends ApiTestCase
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

    public function testAnonymousIsRejected(): void
    {
        $client = self::createClient();
        $client->request('GET', '/api/saved-searches');
        self::assertResponseStatusCodeSame(401);
    }

    public function testCreateListWithUnreadMatchIdsAndDelete(): void
    {
        $client = self::createClient();
        $user = $this->factory()->create('saver@example.com');
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
        $created = $this->payload($client);
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
        $again = $this->payload($client);
        self::assertIsArray($again['savedSearch']);
        self::assertSame($savedId, $again['savedSearch']['id']);

        // List carries the ids of the unread matches (whole-word "punk" matches the one unread entry).
        $client->request('GET', '/api/saved-searches', server: $headers);
        self::assertResponseIsSuccessful();
        $list = $this->payload($client);
        self::assertIsArray($list['savedSearches']);
        self::assertCount(1, $list['savedSearches']);
        self::assertIsArray($list['savedSearches'][0]);
        self::assertSame('punk', $list['savedSearches'][0]['term']);
        self::assertSame([$entry->getId()], $list['savedSearches'][0]['unreadEntryIds']);
        self::assertSame(0, $list['savedSearches'][0]['position']);

        // Delete.
        $client->request('DELETE', '/api/saved-searches/' . $savedId, server: $headers);
        self::assertResponseStatusCodeSame(204);

        $client->request('GET', '/api/saved-searches', server: $headers);
        $empty = $this->payload($client);
        self::assertIsArray($empty['savedSearches']);
        self::assertCount(0, $empty['savedSearches']);
    }

    public function testValidationRejectsShortTerm(): void
    {
        $client = self::createClient();
        $headers = $this->authHeaderFor($this->factory()->create('shorty@example.com'));
        $client->request(
            'POST',
            '/api/saved-searches',
            server: $headers,
            content: json_encode(['term' => 'ab', 'wholeWord' => false], \JSON_THROW_ON_ERROR),
        );
        self::assertResponseStatusCodeSame(422);
        // The violation must come from the DTO's own Length constraint
        // (property path "term"), not from the redundant length check
        // SavedSearchMatchIds's SearchTerms::fromInput() would apply
        // downstream (property path "q") — that only fires once the entity
        // is already persisted, which a request this short must never reach.
        $body = $this->payload($client);
        self::assertIsArray($body['errors']);
        self::assertArrayHasKey('term', $body['errors']);
    }

    public function testThreeCharTermIsAcceptedAtTheLowerBound(): void
    {
        $client = self::createClient();
        $headers = $this->authHeaderFor($this->factory()->create('lower-bound@example.com'));
        $client->request(
            'POST',
            '/api/saved-searches',
            server: $headers,
            content: json_encode(['term' => 'abc', 'wholeWord' => false], \JSON_THROW_ON_ERROR),
        );
        self::assertResponseStatusCodeSame(201);
    }

    public function testHundredCharTermIsAcceptedAndHundredOneIsRejected(): void
    {
        $client = self::createClient();
        $headers = $this->authHeaderFor($this->factory()->create('upper-bound@example.com'));

        $client->request(
            'POST',
            '/api/saved-searches',
            server: $headers,
            content: json_encode(['term' => str_repeat('a', 100), 'wholeWord' => false], \JSON_THROW_ON_ERROR),
        );
        self::assertResponseStatusCodeSame(201);

        $client->request(
            'POST',
            '/api/saved-searches',
            server: $headers,
            content: json_encode(['term' => str_repeat('b', 101), 'wholeWord' => false], \JSON_THROW_ON_ERROR),
        );
        self::assertResponseStatusCodeSame(422);
        // Same reasoning as the short-term case: the rejection must be the
        // DTO's own Length constraint ("term"), not the redundant downstream
        // check in SavedSearchMatchIds ("q") that only runs after persist.
        $body = $this->payload($client);
        self::assertIsArray($body['errors']);
        self::assertArrayHasKey('term', $body['errors']);
    }

    public function testWholeWordDefaultsToFalseWhenOmitted(): void
    {
        $client = self::createClient();
        $headers = $this->authHeaderFor($this->factory()->create('default-wholeword@example.com'));

        $client->request(
            'POST',
            '/api/saved-searches',
            server: $headers,
            content: json_encode(['term' => 'defaulted'], \JSON_THROW_ON_ERROR),
        );
        self::assertResponseStatusCodeSame(201);
        $created = $this->payload($client);
        self::assertIsArray($created['savedSearch']);
        self::assertFalse($created['savedSearch']['wholeWord']);
    }

    public function testWholeWordAndSubstringMatchIdsAreIndependentAcrossTheList(): void
    {
        $client = self::createClient();
        $user = $this->factory()->create('counts-independent@example.com');
        $headers = $this->authHeaderFor($user);

        $em = $this->em();
        $feed = new Feed('https://example.com/counts.xml');
        $feed->setTitle('Example');
        $em->persist($feed);
        $em->persist(new Subscription($user, $feed, new \DateTimeImmutable('2026-07-01T00:00:00Z')));
        $em->persist(new Entry(
            $feed,
            'whole-only',
            'https://example.com/whole-only',
            'A punk revival',
            new \DateTimeImmutable('2026-07-01T00:00:00Z'),
            new \DateTimeImmutable('2026-07-10T00:00:00Z'),
        ));
        $em->persist(new Entry(
            $feed,
            'substring-only',
            'https://example.com/substring-only',
            'Steampunk gadgets',
            new \DateTimeImmutable('2026-07-01T00:00:00Z'),
            new \DateTimeImmutable('2026-07-10T00:00:00Z'),
        ));
        $em->flush();

        // Whole-word "punk" matches only the exact-word entry; substring
        // "punk" matches both. Two saved searches on the same term but
        // different wholeWord must carry two distinct, non-zero counts.
        $em->persist(new SavedSearch($user, 'punk', true));
        $em->persist(new SavedSearch($user, 'punk', false));
        $em->flush();

        $client->request('GET', '/api/saved-searches', server: $headers);
        self::assertResponseIsSuccessful();
        $list = $this->payload($client);
        self::assertIsArray($list['savedSearches']);
        self::assertCount(2, $list['savedSearches']);

        $wholeWordCount = null;
        $substringCount = null;
        foreach ($list['savedSearches'] as $savedSearch) {
            self::assertIsArray($savedSearch);
            self::assertIsArray($savedSearch['unreadEntryIds']);
            if ($savedSearch['wholeWord']) {
                $wholeWordCount = count($savedSearch['unreadEntryIds']);
            } else {
                $substringCount = count($savedSearch['unreadEntryIds']);
            }
        }
        self::assertSame(1, $wholeWordCount);
        self::assertSame(2, $substringCount);
    }

    public function testDeleteAnotherUsersSavedSearchIs404(): void
    {
        $client = self::createClient();
        $em = $this->em();
        $owner = $this->factory()->create('owner3@example.com');
        $saved = new SavedSearch($owner, 'private', false);
        $em->persist($saved);
        $em->flush();

        $headers = $this->authHeaderFor($this->factory()->create('intruder3@example.com'));
        $client->request('DELETE', '/api/saved-searches/' . $saved->getId(), server: $headers);
        self::assertResponseStatusCodeSame(404); // not 403 — do not reveal existence
    }
}
