<?php

declare(strict_types=1);

namespace App\Tests\Service\Worker;

use App\Service\Worker\Handler\RebuildDueTrendingStoriesHandler;
use App\Service\Worker\Message\RebuildDueTrendingStories;
use App\Tests\DbTestCase;
use App\Tests\Support\RecordingLogger;
use App\Tests\Support\TrendingWiring;
use Symfony\Component\Clock\MockClock;

final class RebuildDueTrendingStoriesHandlerTest extends DbTestCase
{
    public function testFiringRebuildsTheDueWindowsAndLogsTheReport(): void
    {
        $logger = new RecordingLogger();
        $rebuild = TrendingWiring::rebuild($this->entityManager, new MockClock('2031-05-14 12:00:00', 'UTC'));

        (new RebuildDueTrendingStoriesHandler($rebuild, $logger))(new RebuildDueTrendingStories());

        self::assertCount(1, $logger->records);
        self::assertSame('Worker trending rebuild finished.', $logger->records[0]['message']);
        $report = $logger->records[0]['context']['report'];
        self::assertIsArray($report);
        self::assertIsArray($report['builds']);
        self::assertSame(['day', 'week'], array_column($report['builds'], 'window'));
    }
}
