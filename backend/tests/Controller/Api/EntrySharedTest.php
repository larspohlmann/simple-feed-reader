<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Entity\Entry;
use App\Entity\EntryShare;
use App\Entity\Feed;
use App\Entity\Subscription;
use App\Entity\User;
use App\Tests\Support\ApiTestCase;
use App\Tests\Support\StoredUserSettings;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

final class EntrySharedTest extends ApiTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
    }

    public function testSharingStampsTheEntryAndUnsharingClearsIt(): void
    {
        [$headers, $user] = $this->bearerAndUser('share-mark@example.com');
        $entryId = $this->subscribedEntry($user, 'share-mark');

        $shared = $this->patchState($headers, $entryId, ['isShared' => true]);
        self::assertTrue($shared['isShared']);
        self::assertNotNull($shared['sharedAt']);

        $cleared = $this->patchState($headers, $entryId, ['isShared' => false]);
        self::assertFalse($cleared['isShared']);
        self::assertNull($cleared['sharedAt']);
    }

    public function testTheSharedListHoldsSharedEntriesNewestShareFirst(): void
    {
        [$headers, $user] = $this->bearerAndUser('share-list@example.com');
        $first = $this->subscribedEntry($user, 'shared-first');
        $second = $this->subscribedEntry($user, 'shared-second');
        $this->subscribedEntry($user, 'never-shared');
        $this->shareAt($user, $first, '2026-10-02T10:00:00Z');
        $this->shareAt($user, $second, '2026-10-02T09:00:00Z');

        $this->client->request('GET', '/api/entries?view=shared', server: $headers);

        self::assertResponseIsSuccessful();
        $entries = $this->payload($this->client)['entries'];
        self::assertIsArray($entries);
        self::assertSame([$first, $second], array_column($entries, 'id'));
        self::assertSame([true, true], array_column($entries, 'isShared'));
    }

    public function testTheSharedListPagesBySharedAt(): void
    {
        [$headers, $user] = $this->bearerAndUser('share-pages@example.com');
        $older = $this->subscribedEntry($user, 'share-older');
        $newer = $this->subscribedEntry($user, 'share-newer');
        $this->shareAt($user, $older, '2026-10-02T08:00:00Z');
        $this->shareAt($user, $newer, '2026-10-02T09:00:00Z');

        $this->client->request('GET', '/api/entries?view=shared&limit=1', server: $headers);
        $nextCursor = $this->payload($this->client)['nextCursor'];
        self::assertIsString($nextCursor);
        $this->client->request(
            'GET',
            '/api/entries?view=shared&limit=1&cursor=' . urlencode($nextCursor),
            server: $headers,
        );

        self::assertSame([$older], array_column((array) $this->payload($this->client)['entries'], 'id'));
    }

    public function testAPatchWithoutIsSharedLeavesTheShareUnchanged(): void
    {
        [$headers, $user] = $this->bearerAndUser('share-keep@example.com');
        $entryId = $this->subscribedEntry($user, 'share-keep');
        $shared = $this->patchState($headers, $entryId, ['isShared' => true]);

        $other = $this->patchState($headers, $entryId, ['isFavorite' => true]);

        self::assertNotNull($other['sharedAt']);
        self::assertSame($shared['sharedAt'], $other['sharedAt']);
        self::assertTrue($other['isShared']);
    }

    public function testAShareIsIgnoredWhileRememberingIsOff(): void
    {
        [$headers, $user] = $this->bearerAndUser('share-off@example.com');
        StoredUserSettings::sharing($this->entityManager(), $user)->setRememberingShares(false);
        $entryId = $this->subscribedEntry($user, 'share-off');

        self::assertFalse($this->patchState($headers, $entryId, ['isShared' => true])['isShared']);
    }

    public function testAShareIsIgnoredWhileSharingIsOff(): void
    {
        [$headers, $user] = $this->bearerAndUser('share-disabled@example.com');
        StoredUserSettings::sharing($this->entityManager(), $user)->setEnabled(false);
        $entryId = $this->subscribedEntry($user, 'share-disabled');

        self::assertFalse($this->patchState($headers, $entryId, ['isShared' => true])['isShared']);
    }

    public function testRemovingStillWorksWhileRememberingIsOff(): void
    {
        [$headers, $user] = $this->bearerAndUser('share-remove-off@example.com');
        $entryId = $this->subscribedEntry($user, 'share-remove-off');
        $this->patchState($headers, $entryId, ['isShared' => true]);
        StoredUserSettings::sharing($this->entityManager(), $user)->setRememberingShares(false);
        $this->entityManager()->flush();

        self::assertFalse($this->patchState($headers, $entryId, ['isShared' => false])['isShared']);
    }

    private function subscribedEntry(User $user, string $guid): int
    {
        $when = new \DateTimeImmutable('2026-10-01T00:00:00Z');
        $feed = new Feed("https://example.com/$guid.xml");
        $this->entityManager()->persist($feed);
        $this->entityManager()->persist(new Subscription($user, $feed, $when));
        $entry = new Entry($feed, $guid, "https://example.com/$guid", $guid, $when, $when);
        $this->entityManager()->persist($entry);
        $this->entityManager()->flush();

        return $entry->requireId();
    }

    /**
     * @param array<string, string> $headers
     * @param array<string, bool>   $patch
     *
     * @return array<string, mixed>
     */
    private function patchState(array $headers, int $entryId, array $patch): array
    {
        $this->client->request(
            'PATCH',
            "/api/entries/$entryId/state",
            server: $headers + ['CONTENT_TYPE' => 'application/json'],
            content: json_encode($patch, \JSON_THROW_ON_ERROR),
        );
        self::assertResponseIsSuccessful();
        $state = $this->payload($this->client)['state'];
        self::assertIsArray($state);

        /** @var array<string, mixed> $state */
        return $state;
    }

    private function shareAt(User $user, int $entryId, string $when): void
    {
        $entry = $this->entityManager()->find(Entry::class, $entryId);
        self::assertInstanceOf(Entry::class, $entry);
        $this->entityManager()->persist(new EntryShare($user, $entry, new \DateTimeImmutable($when)));
        $this->entityManager()->flush();
    }
}
