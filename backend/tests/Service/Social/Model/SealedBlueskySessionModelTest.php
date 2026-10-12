<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\Model;

use App\Entity\SealedSecret;
use App\Service\Social\Model\SealedBlueskySessionModel;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SealedBlueskySessionModelTest extends TestCase
{
    public function testACacheEntryReadsBackAsTheSameSession(): void
    {
        $session = new SealedBlueskySessionModel(
            new SealedSecret('c', 'n', 's', 2),
            'alice.bsky.social',
            'https://pds.example.test',
        );

        self::assertEquals($session, SealedBlueskySessionModel::fromCacheEntryOrNull($session->toCacheEntry()));
    }

    #[DataProvider('damagedEntries')]
    public function testADamagedEntryReadsAsNoSession(mixed $stored): void
    {
        self::assertNull(SealedBlueskySessionModel::fromCacheEntryOrNull($stored));
    }

    /** @return iterable<string, array{mixed}> */
    public static function damagedEntries(): iterable
    {
        $whole = [
            'ciphertext' => 'c',
            'nonce' => 'n',
            'salt' => 's',
            'version' => 2,
            'handle' => 'alice.bsky.social',
            'pdsUrl' => 'https://pds.example.test',
        ];
        yield 'not an array' => ['token'];
        yield 'no ciphertext' => [array_diff_key($whole, ['ciphertext' => true])];
        yield 'version as text' => [['version' => '2'] + $whole];
        yield 'no pds' => [array_diff_key($whole, ['pdsUrl' => true])];
    }
}
