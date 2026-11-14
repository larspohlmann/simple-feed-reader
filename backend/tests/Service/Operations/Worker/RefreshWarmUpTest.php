<?php

declare(strict_types=1);

namespace App\Tests\Service\Operations\Worker;

use App\Service\Ingestion\Refresh\Model\RefreshRequestModel;
use App\Service\Operations\Worker\RefreshWarmUp;
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
}
