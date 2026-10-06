<?php

declare(strict_types=1);

namespace App\Tests\Service\Worker;

use App\Service\Refresh\Model\RefreshRequestModel;
use App\Service\Worker\RefreshWarmUp;
use PHPUnit\Framework\TestCase;

final class RefreshWarmUpTest extends TestCase
{
    public function testTheBatchRampsUpOverTheFirstFiringsOfAProcess(): void
    {
        $warmUp = new RefreshWarmUp();

        $limits = [
            $warmUp->nextBatchLimit(),
            $warmUp->nextBatchLimit(),
            $warmUp->nextBatchLimit(),
            $warmUp->nextBatchLimit(),
        ];

        self::assertSame(
            [10, 25, RefreshRequestModel::DEFAULT_BATCH_LIMIT, RefreshRequestModel::DEFAULT_BATCH_LIMIT],
            $limits,
        );
    }

    public function testEveryWarmUpBatchIsSmallerThanTheSteadyStateBatch(): void
    {
        $warmUp = new RefreshWarmUp();

        self::assertLessThan(RefreshRequestModel::DEFAULT_BATCH_LIMIT, $warmUp->nextBatchLimit());
        self::assertLessThan(RefreshRequestModel::DEFAULT_BATCH_LIMIT, $warmUp->nextBatchLimit());
    }
}
