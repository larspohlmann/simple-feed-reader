<?php

declare(strict_types=1);

namespace App\Tests\Service\Comments\Model;

use App\Service\Comments\Model\CachedCommentsModel;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CachedCommentsModelTest extends TestCase
{
    public function testACacheEntryRoundTrips(): void
    {
        $cached = new CachedCommentsModel('the thread', new \DateTimeImmutable('@1760000000'));

        self::assertEquals($cached, CachedCommentsModel::fromCacheEntryOrNull($cached->toCacheEntry()));
    }

    #[DataProvider('halfCorruptEntries')]
    public function testAHalfCorruptEntryReadsAsAMiss(mixed $stored): void
    {
        self::assertNull(CachedCommentsModel::fromCacheEntryOrNull($stored));
    }

    /** @return iterable<string, array{mixed}> */
    public static function halfCorruptEntries(): iterable
    {
        yield 'a string' => ['the thread'];
        yield 'an object' => [new \stdClass()];
        yield 'a time that is no timestamp' => [['body' => 'the thread', 'fetchedAt' => 'soon']];
        yield 'a body that is no string' => [['body' => 1, 'fetchedAt' => 1]];
    }
}
