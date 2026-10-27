<?php

declare(strict_types=1);

namespace App\Tests\Service\Worker;

use App\Service\Transcript\TranscriptSweep;
use App\Service\Worker\Handler\CompleteQueuedTranscriptsHandler;
use App\Service\Worker\Message\CompleteQueuedTranscripts;
use App\Tests\DbTestCase;
use App\Tests\Support\RecordingLogger;

final class CompleteQueuedTranscriptsHandlerTest extends DbTestCase
{
    public function testFiringSweepsAndLogsTheReport(): void
    {
        $sweep = self::getContainer()->get(TranscriptSweep::class);
        self::assertInstanceOf(TranscriptSweep::class, $sweep);
        $logger = new RecordingLogger();
        $handler = new CompleteQueuedTranscriptsHandler($sweep, $logger);

        $handler(new CompleteQueuedTranscripts());

        self::assertCount(1, $logger->records);
        self::assertSame('info', $logger->records[0]['level']);
        self::assertSame('Worker transcript sweep finished.', $logger->records[0]['message']);
        self::assertSame(['claimed' => 0, 'filled' => 0], $logger->records[0]['context']['report']);
    }
}
