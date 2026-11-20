<?php

declare(strict_types=1);

namespace App\Tests\Repository;

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

        $first = $this->items->ensure($key);
        $second = $this->items->ensure($key);

        self::assertSame($first, $second);
        self::assertSame(1, $this->rowCount());
    }

    public function testEnsureStoresTheKeyAndItsHash(): void
    {
        $key = new CanonicalKeyModel(CanonicalItemKind::Mastodon, 'https://mastodon.social/users/a/statuses/1');

        $id = $this->items->ensure($key);

        self::assertSame(
            ['kind' => 'mastodon', 'canonical_key' => $key->key, 'key_hash' => $key->keyHash()],
            $this->entityManager->getConnection()->fetchAssociative(
                'SELECT kind, canonical_key, key_hash FROM canonical_item WHERE id = ?',
                [$id],
            ),
        );
    }

    public function testOneKeyOfTwoKindsIsTwoItems(): void
    {
        $lemmy = $this->items->ensure(new CanonicalKeyModel(CanonicalItemKind::Lemmy, 'https://x.test/post/1'));
        $mastodon = $this->items->ensure(new CanonicalKeyModel(CanonicalItemKind::Mastodon, 'https://x.test/post/1'));

        self::assertNotSame($lemmy, $mastodon);
        self::assertSame(2, $this->rowCount());
    }

    public function testFindByIdsKeysTheItemsByIdAndSkipsUnknownIds(): void
    {
        $first = $this->items->ensure(new CanonicalKeyModel(CanonicalItemKind::Lemmy, 'https://x.test/post/1'));
        $second = $this->items->ensure(new CanonicalKeyModel(CanonicalItemKind::Lemmy, 'https://x.test/post/2'));

        $found = $this->items->findByIds([$first, $second, $second + 100]);

        self::assertEqualsCanonicalizing([$first, $second], array_keys($found));
        self::assertSame($second, $found[$second]->requireId());
    }

    public function testFindByIdsOfNoIdsAsksNothing(): void
    {
        self::assertSame([], $this->items->findByIds([]));
    }

    private function rowCount(): int
    {
        $count = $this->entityManager->getConnection()->fetchOne('SELECT COUNT(*) FROM canonical_item');
        self::assertIsNumeric($count);

        return (int) $count;
    }
}
