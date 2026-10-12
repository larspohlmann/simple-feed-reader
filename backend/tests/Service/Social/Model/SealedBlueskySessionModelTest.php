<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\Model;

use App\Entity\SealedSecret;
use App\Service\Social\Model\SealedBlueskySessionModel;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SealedBlueskySessionModelTest extends TestCase
{
    private const array WHOLE = [
        'ciphertext' => 'c',
        'nonce' => 'n',
        'salt' => 's',
        'version' => 2,
        'handle' => 'alice.bsky.social',
    ];

    public function testACacheEntryReadsBackAsTheSameSession(): void
    {
        $session = new SealedBlueskySessionModel(new SealedSecret('c', 'n', 's', 2), 'alice.bsky.social');

        self::assertEquals($session, SealedBlueskySessionModel::fromCacheEntryOrNull($session->toCacheEntry()));
    }

    public function testAnEntryStillCarryingItsPdsReadsAsTheSession(): void
    {
        $stored = self::WHOLE + ['pdsUrl' => 'https://pds.example.test'];

        self::assertEquals(
            new SealedBlueskySessionModel(new SealedSecret('c', 'n', 's', 2), 'alice.bsky.social'),
            SealedBlueskySessionModel::fromCacheEntryOrNull($stored),
        );
    }

    #[DataProvider('damagedEntries')]
    public function testADamagedEntryReadsAsNoSession(mixed $stored): void
    {
        self::assertNull(SealedBlueskySessionModel::fromCacheEntryOrNull($stored));
    }

    /** @return iterable<string, array{mixed}> */
    public static function damagedEntries(): iterable
    {
        yield 'not an array' => ['token'];
        yield 'no ciphertext' => [array_diff_key(self::WHOLE, ['ciphertext' => true])];
        yield 'no nonce' => [array_diff_key(self::WHOLE, ['nonce' => true])];
        yield 'no salt' => [array_diff_key(self::WHOLE, ['salt' => true])];
        yield 'no handle' => [array_diff_key(self::WHOLE, ['handle' => true])];
        yield 'version as text' => [['version' => '2'] + self::WHOLE];
    }
}
