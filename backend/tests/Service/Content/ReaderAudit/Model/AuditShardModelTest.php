<?php

declare(strict_types=1);

namespace App\Tests\Service\ReaderAudit\Model;

use App\Service\ReaderAudit\Model\AuditShardModel;
use App\Service\ReaderAudit\Model\SampledEntryModel;
use PHPUnit\Framework\TestCase;

final class AuditShardModelTest extends TestCase
{
    public function testASingleShardKeepsTheWholeSample(): void
    {
        $sample = $this->sample(5);

        self::assertSame($sample, (new AuditShardModel(1, 1))->pick($sample));
    }

    public function testNoShardCountKeepsTheWholeSample(): void
    {
        $sample = $this->sample(5);

        self::assertSame($sample, (new AuditShardModel(0, 0))->pick($sample));
    }

    public function testAShardKeepsEveryEntryAtItsOwnOffset(): void
    {
        $sample = $this->sample(5);

        self::assertSame([$sample[1], $sample[3]], (new AuditShardModel(1, 2))->pick($sample));
        self::assertSame([$sample[0], $sample[3]], (new AuditShardModel(0, 3))->pick($sample));
    }

    /** @return list<SampledEntryModel> */
    private function sample(int $size): array
    {
        return array_map(
            static fn (int $entryId): SampledEntryModel => new SampledEntryModel(
                $entryId,
                1,
                1,
                'Feed',
                'Title ' . $entryId,
                'https://example.com/' . $entryId,
                null,
                false,
            ),
            range(0, $size - 1),
        );
    }
}
