<?php

declare(strict_types=1);

namespace App\Tests\Service\Operations\Worker;

use App\Service\Curation\YourStream\YourStreamWordSweep;
use App\Service\Operations\Worker\Handler\RefreshYourStreamWordsHandler;
use App\Service\Operations\Worker\Message\RefreshYourStreamWords;
use App\Tests\DbTestCase;
use App\Tests\Support\RecordingLogger;

final class RefreshYourStreamWordsHandlerTest extends DbTestCase
{
    public function testFiringRunsTheSweepAndLogsTheReport(): void
    {
        $logger = new RecordingLogger();
        $sweep = self::getContainer()->get(YourStreamWordSweep::class);
        self::assertInstanceOf(YourStreamWordSweep::class, $sweep);

        (new RefreshYourStreamWordsHandler($sweep, $logger))(new RefreshYourStreamWords());

        self::assertCount(1, $logger->records);
        self::assertSame('Worker your-stream words refresh finished.', $logger->records[0]['message']);
        self::assertSame(
            ['raritiesRefreshed' => 0],
            $logger->records[0]['context']['report'],
        );
    }
}
