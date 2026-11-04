<?php

declare(strict_types=1);

namespace App\Tests\Service\Maintenance\MaintenanceSweep;

use App\Service\Maintenance\MaintenanceSweep\TranscriptsTickSweep;
use App\Service\Transcript\TranscriptSource\TranscriptSourceInterface;
use App\Service\Transcript\TranscriptSweep;
use App\Tests\DbTestCase;
use App\Tests\Support\ScriptedTranscriptSource;
use App\Tests\Support\TranscriptFixtures;
use Symfony\Component\Clock\MockClock;

final class TranscriptsTickSweepTest extends DbTestCase
{
    public function testFetchesTheWholeFetchesThatFitBeforeTheDeadline(): void
    {
        new TranscriptFixtures($this->entityManager)->pendingTranscripts(3);
        $source = ScriptedTranscriptSource::answering(TranscriptFixtures::READABLE);
        self::getContainer()->set(TranscriptSourceInterface::class, $source);
        $clock = new MockClock('2026-10-27 12:00:00', 'UTC');

        $report = $this->sweep($clock)->run($clock->now()->modify('+9 seconds'));

        self::assertSame(['claimed' => 2, 'filled' => 2], $report->outcome);
        self::assertCount(2, $source->fetchedUrls);
    }

    public function testADeadlineUnderOneFetchAwayFetchesNothing(): void
    {
        new TranscriptFixtures($this->entityManager)->pendingTranscripts(1);
        $clock = new MockClock('2026-10-27 12:00:00', 'UTC');

        $report = $this->sweep($clock)->run($clock->now()->modify('+3 seconds'));

        self::assertSame(['claimed' => 0, 'filled' => 0], $report->outcome);
    }

    private function sweep(MockClock $clock): TranscriptsTickSweep
    {
        $transcripts = self::getContainer()->get(TranscriptSweep::class);
        self::assertInstanceOf(TranscriptSweep::class, $transcripts);

        return new TranscriptsTickSweep($transcripts, $clock);
    }
}
