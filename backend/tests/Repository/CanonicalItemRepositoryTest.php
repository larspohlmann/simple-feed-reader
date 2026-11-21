<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\Entry;
use App\Entity\Feed;
use App\Entity\Subscription;
use App\Entity\User;
use App\Enum\CanonicalItemKind;
use App\Repository\CanonicalItemRepository;
use App\Service\Foundation\CanonicalItem\Model\CanonicalKeyModel;
use App\Tests\DbTestCase;

final class CanonicalItemRepositoryTest extends DbTestCase
{
    private CanonicalItemRepository $items;

    protected function setUp(): void
    {
        parent::setUp();

        $items = self::getContainer()->get(CanonicalItemRepository::class);
        self::assertInstanceOf(CanonicalItemRepository::class, $items);
        $this->items = $items;
    }

    public function testEnsureIsIdempotentForOneKey(): void
    {
        $key = new CanonicalKeyModel(CanonicalItemKind::Lemmy, 'https://lemmy.world/post/7');

        $first = $this->ensureItem($key->kind, $key->key);
        $second = $this->ensureItem($key->kind, $key->key);

        self::assertSame($first, $second);
        self::assertSame(1, $this->countRows());
    }

    public function testEnsureStoresTheKeyAndItsHash(): void
    {
        $key = new CanonicalKeyModel(CanonicalItemKind::Mastodon, 'https://mastodon.social/users/a/statuses/1');

        $id = $this->ensureItem($key->kind, $key->key);

        self::assertSame(
            [
                'kind' => 'mastodon',
                'canonical_key' => $key->key,
                'key_hash' => $key->keyHash(),
                'created_at' => '2026-11-20 08:30:00',
            ],
            $this->entityManager->getConnection()->fetchAssociative(
                'SELECT kind, canonical_key, key_hash, created_at FROM canonical_item WHERE id = ?',
                [$id],
            ),
        );
    }

    public function testOneKeyOfTwoKindsIsTwoItems(): void
    {
        $lemmy = $this->ensureItem(CanonicalItemKind::Lemmy, 'https://x.test/post/1');
        $mastodon = $this->ensureItem(CanonicalItemKind::Mastodon, 'https://x.test/post/1');

        self::assertNotSame($lemmy, $mastodon);
        self::assertSame(2, $this->countRows());
    }

    public function testEnsureAllStoresOnlyTheMissingKeysOfABatchAndNamesEveryItem(): void
    {
        $existing = new CanonicalKeyModel(CanonicalItemKind::Lemmy, 'https://x.test/post/1');
        $existingId = $this->ensureItem($existing->kind, $existing->key);
        $fresh = new CanonicalKeyModel(CanonicalItemKind::Lemmy, 'https://x.test/post/2');
        $sameKeyOtherKind = new CanonicalKeyModel(CanonicalItemKind::Mastodon, 'https://x.test/post/2');

        $items = $this->items->ensureAll(
            [$existing, $fresh, $sameKeyOtherKind, $fresh],
            self::buildCreatedAt(),
        );

        self::assertCount(3, $items);
        self::assertSame($existingId, $items[$existing->identity()]->requireId());
        self::assertNotSame($items[$fresh->identity()], $items[$sameKeyOtherKind->identity()]);
        self::assertSame(3, $this->countRows());
    }

    public function testEnsureAllSpansStatementsForALargeBatch(): void
    {
        $keys = array_map(
            static fn (int $number): CanonicalKeyModel => new CanonicalKeyModel(
                CanonicalItemKind::Bluesky,
                'at://did:plc:x/app.bsky.feed.post/' . $number,
            ),
            range(1, 230),
        );

        $items = $this->items->ensureAll($keys, self::buildCreatedAt());

        self::assertCount(230, $items);
        self::assertSame(230, $this->countRows());
        self::assertCount(230, $this->items->ensureAll($keys, self::buildCreatedAt()));
        self::assertSame(230, $this->countRows());
    }

    public function testEnsureAllOfNoKeysAsksNothing(): void
    {
        self::assertSame([], $this->items->ensureAll([], self::buildCreatedAt()));
    }

    public function testFindByIdsKeysTheItemsByIdAndSkipsUnknownIds(): void
    {
        $first = $this->ensureItem(CanonicalItemKind::Lemmy, 'https://x.test/post/1');
        $second = $this->ensureItem(CanonicalItemKind::Lemmy, 'https://x.test/post/2');

        $found = $this->items->findByIds([$first, $second, $second + 100]);

        self::assertEqualsCanonicalizing([$first, $second], array_keys($found));
        self::assertSame($second, $found[$second]->requireId());
    }

    public function testFindByIdsOfNoIdsAsksNothing(): void
    {
        self::assertSame([], $this->items->findByIds([]));
    }

    public function testFindsTheLowestReadableCopyPerHash(): void
    {
        $key = new CanonicalKeyModel(CanonicalItemKind::Mastodon, 'https://m.test/users/a/statuses/1');
        $user = $this->persistUser('reader@example.com');
        $first = $this->persistSubscribedEntry($user, 'https://a.test/feed', $key);
        $second = $this->persistSubscribedEntry($user, 'https://b.test/feed', $key);
        $this->entityManager->flush();

        self::assertSame(
            [$key->keyHash() => min($first->requireId(), $second->requireId())],
            $this->items->findReadableEntryIds(
                $user->requireId(),
                CanonicalItemKind::Mastodon,
                [$key->keyHash(), 'missing'],
            ),
        );
    }

    public function testIgnoresAnotherUsersSubscription(): void
    {
        $key = new CanonicalKeyModel(CanonicalItemKind::Mastodon, 'https://m.test/users/a/statuses/2');
        $reader = $this->persistUser('a@example.com');
        $other = $this->persistUser('b@example.com');
        $this->persistSubscribedEntry($other, 'https://a.test/feed', $key);
        $this->entityManager->flush();

        self::assertSame(
            [],
            $this->items->findReadableEntryIds($reader->requireId(), CanonicalItemKind::Mastodon, [$key->keyHash()]),
        );
    }

    public function testIgnoresAnUnsubscribedFeedsEntry(): void
    {
        $key = new CanonicalKeyModel(CanonicalItemKind::Bluesky, 'at://did:plc:x/app.bsky.feed.post/1');
        $user = $this->persistUser('reader@example.com');
        $entry = $this->persistSubscribedEntry($user, 'https://a.test/feed', $key);
        $this->entityManager->flush();
        $subscription = $this->entityManager->getRepository(Subscription::class)
            ->findOneBy(['feed' => $entry->getFeed()]);
        self::assertNotNull($subscription);
        $this->entityManager->remove($subscription);
        $this->entityManager->flush();

        self::assertSame(
            [],
            $this->items->findReadableEntryIds($user->requireId(), CanonicalItemKind::Bluesky, [$key->keyHash()]),
        );
    }

    public function testIgnoresTheSameHashOfAnotherKind(): void
    {
        $key = new CanonicalKeyModel(CanonicalItemKind::Lemmy, 'https://lemmy.test/post/9');
        $user = $this->persistUser('reader@example.com');
        $this->persistSubscribedEntry($user, 'https://a.test/feed', $key);
        $this->entityManager->flush();

        self::assertSame(
            [],
            $this->items->findReadableEntryIds($user->requireId(), CanonicalItemKind::Mastodon, [$key->keyHash()]),
        );
    }

    public function testAnEmptyAskMakesNoQuery(): void
    {
        self::assertSame([], $this->items->findReadableEntryIds(1, CanonicalItemKind::Lemmy, []));
    }

    private function persistUser(string $email): User
    {
        $user = new User($email, self::buildCreatedAt());
        $this->entityManager->persist($user);

        return $user;
    }

    private function persistSubscribedEntry(User $user, string $feedUrl, CanonicalKeyModel $key): Entry
    {
        $feed = new Feed($feedUrl);
        $this->entityManager->persist($feed);
        $this->entityManager->persist(new Subscription($user, $feed, self::buildCreatedAt()));
        $entry = new Entry($feed, 'guid-' . $feedUrl, null, 'Title', self::buildCreatedAt(), self::buildCreatedAt());
        $entry->linkTo($this->items->ensureAll([$key], self::buildCreatedAt())[$key->identity()]);
        $this->entityManager->persist($entry);

        return $entry;
    }

    private function ensureItem(CanonicalItemKind $kind, string $canonicalKey): int
    {
        $key = new CanonicalKeyModel($kind, $canonicalKey);

        return $this->items->ensureAll([$key], self::buildCreatedAt())[$key->identity()]->requireId();
    }

    private static function buildCreatedAt(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-11-20 08:30:00');
    }

    private function countRows(): int
    {
        $count = $this->entityManager->getConnection()->fetchOne('SELECT COUNT(*) FROM canonical_item');
        self::assertIsNumeric($count);

        return (int) $count;
    }
}
