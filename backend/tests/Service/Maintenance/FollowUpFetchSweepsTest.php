<?php

declare(strict_types=1);

namespace App\Tests\Service\Maintenance;

use App\Entity\Feed;
use App\Service\LinkCard\LinkCardSweep;
use App\Service\Maintenance\FollowUpFetchSweeps;
use App\Service\Transcript\TranscriptSource\TranscriptSourceInterface;
use App\Service\Transcript\TranscriptSweep;
use App\Tests\DbTestCase;
use App\Tests\Support\ImageVerificationSweepWiring;
use App\Tests\Support\PendingImageFixtures;
use App\Tests\Support\PngImageFactory;
use App\Tests\Support\ScriptedTranscriptSource;
use App\Tests\Support\StubGuardedImageDownloader;
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

    public function testImageVerificationSpendsHalfOfWhatIsLeft(): void
    {
        self::assertSame(1, $this->imagesMeasuredWithinSecondsOfTheDeadline(40));
    }

    public function testImageVerificationStopsAtItsOwnCapInALongWindow(): void
    {
        self::assertSame(2, $this->imagesMeasuredWithinSecondsOfTheDeadline(1000));
    }

    private function imagesMeasuredWithinSecondsOfTheDeadline(int $seconds): int
    {
        $clock = new MockClock('2026-10-27 12:00:00', 'UTC');
        $feed = new Feed('https://example.test/feed.xml');
        $this->entityManager->persist($feed);
        $images = new PendingImageFixtures($this->entityManager);
        foreach (['one', 'two', 'three'] as $guid) {
            $images->pendingImage($feed, $guid, 'https://i/' . $guid . '.png');
        }
        $this->entityManager->flush();
        $downloader = new StubGuardedImageDownloader($clock);
        $downloader->willAlwaysReturn(PngImageFactory::bytes(600, 400));
        $downloader->secondsPerDownload = 30;

        $report = $this->sweeps($clock, $downloader)->sweep($clock->now()->modify(\sprintf('+%d seconds', $seconds)));

        return $report->imageVerification->measured;
    }

    private function sweeps(
        MockClock $clock,
        StubGuardedImageDownloader $downloader = new StubGuardedImageDownloader(),
    ): FollowUpFetchSweeps {
        $linkCards = self::getContainer()->get(LinkCardSweep::class);
        self::assertInstanceOf(LinkCardSweep::class, $linkCards);
        $transcripts = self::getContainer()->get(TranscriptSweep::class);
        self::assertInstanceOf(TranscriptSweep::class, $transcripts);
        $images = ImageVerificationSweepWiring::build(self::getContainer(), $this->entityManager, $downloader, $clock);

        return new FollowUpFetchSweeps($images, $linkCards, $transcripts, $clock);
    }
}
