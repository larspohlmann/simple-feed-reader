<?php

declare(strict_types=1);

namespace App\Tests\Service\Operations\Worker;

use App\Service\Curation\Recommendation\Stream\ForYouStreamSweep;
use App\Service\Operations\Worker\Handler\SweepForYouStreamHandler;
use App\Service\Operations\Worker\Message\SweepForYouStream;
use App\Tests\DbTestCase;
use App\Tests\Support\RecordingLogger;

final class SweepForYouStreamHandlerTest extends DbTestCase
{
    public function testTheHandlerSweepsWithASixtySecondBudgetAndLogsTheReport(): void
    {
        $logger = new RecordingLogger();
        $sweep = self::getContainer()->get(ForYouStreamSweep::class);
        self::assertInstanceOf(ForYouStreamSweep::class, $sweep);

        (new SweepForYouStreamHandler($sweep, $logger))(new SweepForYouStream());

        self::assertCount(1, $logger->records);
        self::assertSame('Worker For you stream sweep finished.', $logger->records[0]['message']);
        self::assertSame(
            ['usersSwept' => 0, 'entriesScanned' => 0, 'matchesInserted' => 0, 'caughtUp' => true],
            $logger->records[0]['context']['report'],
        );
    }
}
