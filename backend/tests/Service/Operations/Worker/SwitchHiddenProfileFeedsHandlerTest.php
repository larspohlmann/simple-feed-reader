<?php

declare(strict_types=1);

namespace App\Tests\Service\Worker;

use App\Service\Social\HiddenProfileSwitchSweep;
use App\Service\Worker\Handler\SwitchHiddenProfileFeedsHandler;
use App\Service\Worker\Message\SwitchHiddenProfileFeeds;
use App\Tests\DbTestCase;
use App\Tests\Support\RecordingLogger;

final class SwitchHiddenProfileFeedsHandlerTest extends DbTestCase
{
    public function testFiringSweepsAndLogsTheReport(): void
    {
        $sweep = self::getContainer()->get(HiddenProfileSwitchSweep::class);
        self::assertInstanceOf(HiddenProfileSwitchSweep::class, $sweep);
        $logger = new RecordingLogger();

        (new SwitchHiddenProfileFeedsHandler($sweep, $logger))(new SwitchHiddenProfileFeeds());

        self::assertCount(1, $logger->records);
        self::assertSame('Worker hidden-profile switch finished.', $logger->records[0]['message']);
        self::assertSame(['started' => 0, 'finished' => 0, 'waiting' => 0], $logger->records[0]['context']['report']);
    }
}
