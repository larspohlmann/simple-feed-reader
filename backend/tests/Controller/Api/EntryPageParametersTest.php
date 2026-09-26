<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Entity\Entry;
use App\Entity\Feed;
use App\Entity\SavedSearch;
use App\Entity\SavedSearchEntry;
use App\Entity\Subscription;
use App\Entity\User;
use App\Repository\EntryQuery;
use App\Tests\Support\ApiTestCase;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

final class EntryPageParametersTest extends ApiTestCase
{
    private const string SAVED_SEARCH_PATH = '/api/entries/saved-searches/{id}';

    public function testAMalformedLimitOnTheEntryListIsAValidationError(): void
    {
        $client = self::createClient();
        $user = $this->factory()->create('page-parameters-entries@example.com');

        $client->request('GET', '/api/entries?limit=abc', server: $this->authHeaderFor($user));

        $this->assertValidationErrorOn($client, 'limit');
    }

    public function testAMalformedLimitOnTheSavedSearchListIsAValidationError(): void
    {
        $client = self::createClient();
        $user = $this->factory()->create('page-parameters-saved@example.com');

        $client->request('GET', '/api/entries/saved-searches?limit=abc', server: $this->authHeaderFor($user));

        $this->assertValidationErrorOn($client, 'limit');
    }

    public function testAMalformedUnreadOnOneSavedSearchIsAValidationError(): void
    {
        $client = self::createClient();
        $user = $this->factory()->create('page-parameters-one@example.com');
        $search = $this->seedSavedSearch($user);

        $client->request('GET', $this->savedSearchPath($search) . '?unread=maybe', server: $this->authHeaderFor($user));

        $this->assertValidationErrorOn($client, 'unread');
    }

    public function testWellFormedPageParametersStillPage(): void
    {
        $client = self::createClient();
        $user = $this->factory()->create('page-parameters-ok@example.com');

        $client->request('GET', '/api/entries?limit=2&unread=1&order=asc', server: $this->authHeaderFor($user));

        self::assertResponseIsSuccessful();
        self::assertSame([], $this->payload($client)['entries']);
    }

    /** @return iterable<string, array{string, list<string>}> */
    public static function unreadSpellings(): iterable
    {
        yield 'true' => ['unread=true', ['Climate unread']];
        yield '1' => ['unread=1', ['Climate unread']];
        yield 'false' => ['unread=false', ['Climate unread', 'Climate read']];
        yield '0' => ['unread=0', ['Climate unread', 'Climate read']];
        yield 'absent' => ['', ['Climate unread', 'Climate read']];
    }

    /** @param list<string> $expectedTitles */
    #[DataProvider('unreadSpellings')]
    public function testUnreadKeepsItsMeaningOnBothSavedSearchLists(string $query, array $expectedTitles): void
    {
        $client = self::createClient();
        $user = $this->factory()->create('page-parameters-unread@example.com');
        $feed = $this->seedSubscribedFeed($user);
        $read = $this->seedEntry($feed, 'Climate read', new \DateTimeImmutable('2026-07-01T00:00:00Z'));
        $unread = $this->seedEntry($feed, 'Climate unread', new \DateTimeImmutable('2026-07-02T00:00:00Z'));
        $search = $this->seedSavedSearch($user, $read, $unread);
        $this->hide($client, $user, $read);

        foreach (['/api/entries/saved-searches', $this->savedSearchPath($search)] as $path) {
            $client->request('GET', $path . '?' . $query, server: $this->authHeaderFor($user));

            self::assertResponseIsSuccessful();
            self::assertSame($expectedTitles, $this->titlesOf($client), $path);
        }
    }

    /** @return iterable<string, array{string}> */
    public static function listPathsWithoutPageParameters(): iterable
    {
        yield 'entries, empty query' => ['/api/entries'];
        yield 'entries, unrelated query' => ['/api/entries?view=all'];
        yield 'saved searches, empty query' => ['/api/entries/saved-searches'];
        yield 'saved searches, unrelated query' => ['/api/entries/saved-searches?foo=bar'];
        yield 'one saved search, empty query' => [self::SAVED_SEARCH_PATH];
        yield 'one saved search, unrelated query' => [self::SAVED_SEARCH_PATH . '?foo=bar'];
    }

    #[DataProvider('listPathsWithoutPageParameters')]
    public function testAbsentPageParametersServeTheNewestFirstPageAtTheDefaultLimit(string $path): void
    {
        $client = self::createClient();
        $user = $this->factory()->create('page-parameters-defaults@example.com');
        $feed = $this->seedSubscribedFeed($user);
        $entries = [];
        for ($day = 1; $day <= EntryQuery::DEFAULT_LIMIT + 1; ++$day) {
            $publishedAt = new \DateTimeImmutable('2026-05-01T00:00:00Z')->modify('+' . $day . ' days');
            $entries[] = $this->seedEntry($feed, 'Climate day ' . $day, $publishedAt);
        }
        $search = $this->seedSavedSearch($user, ...$entries);

        $client->request('GET', $this->withSavedSearchId($path, $search), server: $this->authHeaderFor($user));

        self::assertResponseIsSuccessful();
        $titles = $this->titlesOf($client);
        self::assertCount(EntryQuery::DEFAULT_LIMIT, $titles);
        self::assertSame('Climate day ' . (EntryQuery::DEFAULT_LIMIT + 1), $titles[0]);
        self::assertNotNull($this->payload($client)['nextCursor']);
    }

    private function assertValidationErrorOn(KernelBrowser $client, string $field): void
    {
        $this->assertRejected($client, 422);
        self::assertSame('validation_error', $this->payload($client)['type']);
        self::assertIsArray($this->payload($client)['errors']);
        self::assertArrayHasKey($field, $this->payload($client)['errors']);
    }

    /** @return list<string> */
    private function titlesOf(KernelBrowser $client): array
    {
        $entries = $this->payload($client)['entries'];
        self::assertIsArray($entries);

        return array_values(array_map(static function (mixed $entry): string {
            self::assertIsArray($entry);
            self::assertIsString($entry['title']);

            return $entry['title'];
        }, $entries));
    }

    private function hide(KernelBrowser $client, User $user, Entry $entry): void
    {
        $client->request(
            'PATCH',
            '/api/entries/' . $entry->requireId() . '/state',
            server: $this->authHeaderFor($user) + ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['isHidden' => true], \JSON_THROW_ON_ERROR),
        );
        self::assertResponseIsSuccessful();
    }

    private function savedSearchPath(SavedSearch $search): string
    {
        return $this->withSavedSearchId(self::SAVED_SEARCH_PATH, $search);
    }

    private function withSavedSearchId(string $path, SavedSearch $search): string
    {
        return str_replace('{id}', (string) $search->requireId(), $path);
    }

    private function seedSubscribedFeed(User $user): Feed
    {
        $feed = new Feed('https://example.com/page-parameters-' . uniqid('', true) . '.xml');
        $feed->setTitle('Seeded');
        $this->em()->persist($feed);
        $this->em()->persist(new Subscription($user, $feed, new \DateTimeImmutable('2026-04-01T00:00:00Z')));

        return $feed;
    }

    private function seedEntry(Feed $feed, string $title, \DateTimeImmutable $publishedAt): Entry
    {
        $link = 'https://example.com/' . uniqid('', true);
        $entry = new Entry($feed, 'guid-' . uniqid('', true), $link, $title, $publishedAt, $publishedAt);
        $entry->setPublishedAt($publishedAt);
        $this->em()->persist($entry);

        return $entry;
    }

    private function seedSavedSearch(User $user, Entry ...$members): SavedSearch
    {
        $search = new SavedSearch($user, 'climate', false);
        $this->em()->persist($search);
        foreach ($members as $member) {
            $this->em()->persist(new SavedSearchEntry($search, $member, new \DateTimeImmutable('2026-09-22T10:00:00')));
        }
        $this->em()->flush();

        return $search;
    }

    /** @return array<string, string> */
    private function authHeaderFor(User $user): array
    {
        $tokens = self::getContainer()->get(JWTTokenManagerInterface::class);
        self::assertInstanceOf(JWTTokenManagerInterface::class, $tokens);

        return ['HTTP_AUTHORIZATION' => 'Bearer ' . $tokens->create($user)];
    }
}
