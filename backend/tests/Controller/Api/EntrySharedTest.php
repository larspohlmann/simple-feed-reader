<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Entity\Entry;
use App\Entity\Feed;
use App\Entity\Subscription;
use App\Entity\User;
use App\Tests\Support\ApiTestCase;
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
}
