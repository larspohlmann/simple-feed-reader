<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\Model;

use App\Service\Social\Model\FollowAvatarsModel;
use App\Service\Social\Model\FollowedAccountModel;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FollowAvatarsModelTest extends TestCase
{
    public function testKeepsOnlyTheFollowsWithAnAvatar(): void
    {
        $avatars = FollowAvatarsModel::of([
            new FollowedAccountModel(
                'did:plc:bob',
                '@bob.test',
                'Bob',
                'https://bsky.app/profile/did:plc:bob/rss',
                'https://cdn.test/bob.jpg',
            ),
            new FollowedAccountModel(
                'did:plc:amy',
                '@amy.test',
                null,
                'https://bsky.app/profile/did:plc:amy/rss',
                null,
            ),
        ]);

        self::assertSame('https://cdn.test/bob.jpg', $avatars->avatarOf('did:plc:bob'));
        self::assertNull($avatars->avatarOf('did:plc:amy'));
        self::assertSame(['did:plc:bob' => 'https://cdn.test/bob.jpg'], $avatars->toCacheEntry());
    }

    public function testNoneIsEmpty(): void
    {
        self::assertTrue(FollowAvatarsModel::none()->isEmpty());
        $stored = FollowAvatarsModel::fromCacheEntryOrNull(['did:plc:bob' => 'https://cdn.test/bob.jpg']);

        self::assertFalse($stored?->isEmpty());
    }

    /** @return iterable<string, array{mixed}> */
    public static function damagedEntries(): iterable
    {
        yield 'not an array' => ['https://cdn.test/bob.jpg'];
        yield 'a list' => [['https://cdn.test/bob.jpg']];
        yield 'a non-string avatar' => [['did:plc:bob' => 42]];
    }

    #[DataProvider('damagedEntries')]
    public function testADamagedCacheEntryReadsAsNothing(mixed $stored): void
    {
        self::assertNull(FollowAvatarsModel::fromCacheEntryOrNull($stored));
    }
}
