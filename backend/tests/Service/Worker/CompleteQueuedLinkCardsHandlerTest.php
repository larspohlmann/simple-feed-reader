<?php

declare(strict_types=1);

namespace App\Tests\Service\Worker;

use App\Service\Worker\Handler\CompleteQueuedLinkCardsHandler;
use App\Service\Worker\Message\CompleteQueuedLinkCards;
use App\Tests\DbTestCase;
use App\Tests\Support\LinkCardSweepWiring;
use App\Tests\Support\RecordingLogger;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;

final class CompleteQueuedLinkCardsHandlerTest extends DbTestCase
{
    public function testFiringSweepsAndLogsTheReport(): void
    {
        $clock = new MockClock('2026-10-11 12:00:00', 'UTC');
        $logger = new RecordingLogger();
        $handler = new CompleteQueuedLinkCardsHandler(
            LinkCardSweepWiring::build($this->entityManager, new MockHttpClient(), $clock),
            $logger,
        );

        $handler(new CompleteQueuedLinkCards());

        self::assertCount(1, $logger->records);
        self::assertSame('info', $logger->records[0]['level']);
        self::assertSame('Worker link-card sweep finished.', $logger->records[0]['message']);
        self::assertSame(['considered' => 0, 'completed' => 0], $logger->records[0]['context']['report']);
    }
}
