<?php

declare(strict_types=1);

namespace App\Tests\Service\Maintenance;

use App\Service\LinkCard\LinkCardSweep;
use App\Service\Maintenance\FollowUpFetchSweeps;
use App\Service\Transcript\TranscriptSource\TranscriptSourceInterface;
use App\Service\Transcript\TranscriptSweep;
use App\Tests\DbTestCase;
use App\Tests\Support\ScriptedTranscriptSource;
use App\Tests\Support\TranscriptFixtures;
use Symfony\Component\Clock\MockClock;

final class FollowUpFetchSweepsTest extends DbTestCase
{
    public function testADeadlineNineSecondsAwayGivesTheTranscriptsTwoFetchesAfterTheLinkCardsSpentNone(): void
    {
        (new TranscriptFixtures($this->entityManager))->pendingTranscripts(3);
        $source = ScriptedTranscriptSource::answering(TranscriptFixtures::READABLE);
        self::getContainer()->set(TranscriptSourceInterface::class, $source);
        $clock = new MockClock('2026-10-27 12:00:00', 'UTC');

        $report = $this->sweeps($clock)->sweep($clock->now()->modify('+9 seconds'));

        self::assertSame(0, $report->linkCards->considered);
        self::assertSame(2, $report->transcripts->claimed);
        self::assertCount(2, $source->fetchedUrls);
    }

    public function testADeadlineUnderOneFetchAwayFetchesNothing(): void
    {
        (new TranscriptFixtures($this->entityManager))->pendingTranscripts(1);
        $clock = new MockClock('2026-10-27 12:00:00', 'UTC');

        $report = $this->sweeps($clock)->sweep($clock->now()->modify('+3 seconds'));

        self::assertSame(0, $report->transcripts->claimed);
    }

    private function sweeps(MockClock $clock): FollowUpFetchSweeps
    {
        $linkCards = self::getContainer()->get(LinkCardSweep::class);
        self::assertInstanceOf(LinkCardSweep::class, $linkCards);
        $transcripts = self::getContainer()->get(TranscriptSweep::class);
        self::assertInstanceOf(TranscriptSweep::class, $transcripts);

        return new FollowUpFetchSweeps($linkCards, $transcripts, $clock);
    }
}
