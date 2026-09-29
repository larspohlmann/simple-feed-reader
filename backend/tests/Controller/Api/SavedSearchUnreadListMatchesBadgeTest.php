<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Entity\Entry;
use App\Entity\EntryState;
use App\Entity\Feed;
use App\Entity\SavedSearch;
use App\Entity\SavedSearchEntry;
use App\Entity\Subscription;
use App\Entity\User;
use App\Tests\Support\ApiTestCase;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;

/**
 * The badge and the unread list must agree when a saved search's newest members are read and only an older one is
 * unread. Fixtures go straight into the membership table: both reads key on effectiveDate and unread state alone.
 */
final class SavedSearchUnreadListMatchesBadgeTest extends ApiTestCase
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

    public function testTheUnreadListAndTheBadgeAgreeWhenOnlyAnOlderMemberIsUnread(): void
    {
        $client = self::createClient();
        $user = $this->factory()->create('badge-parity@example.com');
        $headers = $this->authHeaderFor($user);

        $entityManager = $this->entityManager();
        $feed = new Feed('https://example.com/climate.xml');
        $feed->setTitle('Climate Feed');
        $entityManager->persist($feed);
        $entityManager->persist(new Subscription($user, $feed, new \DateTimeImmutable('2026-07-01T00:00:00Z')));
        $search = new SavedSearch($user, 'climate', false);
        $entityManager->persist($search);
        $entityManager->flush();

        $now = new \DateTimeImmutable('2026-09-22T10:00:00Z');
        $createdAt = $now->modify('-1 day');
        $newest = $this->member($search, $feed, 'newest', $now->modify('-1 hour'), $createdAt);
        $middle = $this->member($search, $feed, 'middle', $now->modify('-2 hours'), $createdAt);
        $oldest = $this->member($search, $feed, 'oldest', $now->modify('-3 hours'), $createdAt);

        $entityManager->persist($this->hidden($user, $newest, $now));
        $entityManager->persist($this->hidden($user, $middle, $now));
        $entityManager->flush();

        $client->request('GET', '/api/saved-searches', server: $headers);
        self::assertResponseIsSuccessful();
        $list = $this->payload($client);
        self::assertIsArray($list['savedSearches']);
        self::assertIsArray($list['savedSearches'][0]);
        $unreadEntryIds = $list['savedSearches'][0]['unreadEntryIds'];
        self::assertSame([$oldest->getId()], $unreadEntryIds);

        $client->request('GET', '/api/entries/saved-searches?unread=1', server: $headers);
        self::assertResponseIsSuccessful();
        $unreadList = $this->payload($client);
        self::assertIsArray($unreadList['entries']);
        self::assertCount(1, $unreadList['entries']);
        self::assertIsArray($unreadList['entries'][0]);
        self::assertSame($oldest->getId(), $unreadList['entries'][0]['id']);

        self::assertSame(\count($unreadEntryIds), \count($unreadList['entries']));
    }

    private function member(
        SavedSearch $search,
        Feed $feed,
        string $guid,
        \DateTimeImmutable $effectiveDate,
        \DateTimeImmutable $createdAt,
    ): Entry {
        $entityManager = $this->entityManager();
        $entry = new Entry(
            $feed,
            $guid,
            'https://example.com/climate/' . $guid,
            'Climate report: ' . $guid,
            $createdAt,
            $effectiveDate,
        );
        $entityManager->persist($entry);
        $entityManager->flush();
        $entityManager->persist(new SavedSearchEntry($search, $entry, $effectiveDate));
        $entityManager->flush();

        return $entry;
    }

    private function hidden(User $user, Entry $entry, \DateTimeImmutable $when): EntryState
    {
        $state = new EntryState($user, $entry);
        $state->hide($when);

        return $state;
    }
}
