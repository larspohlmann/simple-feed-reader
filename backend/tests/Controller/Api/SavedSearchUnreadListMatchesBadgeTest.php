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
 * The regression test for the Docker bug the black-box e2e once caught
 * (spec §10): the badge and the unread list must agree even when the newest
 * members of a saved search are read and only an older one is still unread.
 * Both reads key on effectiveDate + unread state, never on createdAt or
 * insertion order, so the fixtures are built directly against the membership
 * table rather than through the matcher — DB-only and engine-independent.
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

        $em = $this->em();
        $feed = new Feed('https://example.com/climate.xml');
        $feed->setTitle('Climate Feed');
        $em->persist($feed);
        $em->persist(new Subscription($user, $feed, new \DateTimeImmutable('2026-07-01T00:00:00Z')));
        $search = new SavedSearch($user, 'climate', false);
        $em->persist($search);
        $em->flush();

        $now = new \DateTimeImmutable('2026-09-22T10:00:00Z');
        $createdAt = $now->modify('-1 day');
        $newest = $this->member($search, $feed, 'newest', $now->modify('-1 hour'), $createdAt);
        $middle = $this->member($search, $feed, 'middle', $now->modify('-2 hours'), $createdAt);
        $oldest = $this->member($search, $feed, 'oldest', $now->modify('-3 hours'), $createdAt);

        $em->persist($this->hidden($user, $newest, $now));
        $em->persist($this->hidden($user, $middle, $now));
        $em->flush();

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
        $em = $this->em();
        $entry = new Entry(
            $feed,
            $guid,
            'https://example.com/climate/' . $guid,
            'Climate report: ' . $guid,
            $createdAt,
            $effectiveDate,
        );
        $em->persist($entry);
        $em->flush();
        $em->persist(new SavedSearchEntry($search, $entry, $effectiveDate));
        $em->flush();

        return $entry;
    }

    private function hidden(User $user, Entry $entry, \DateTimeImmutable $when): EntryState
    {
        $state = new EntryState($user, $entry);
        $state->hide($when);

        return $state;
    }
}
