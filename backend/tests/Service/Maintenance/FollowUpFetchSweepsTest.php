<?php

declare(strict_types=1);

namespace App\Tests\Service\Maintenance;

use App\Entity\Entry;
use App\Entity\Feed;
use App\Repository\PendingImageVerificationRepository;
use App\Service\Clock\NaiveUtcClock;
use App\Service\Image\ImageVerificationSweep;
use App\Service\Image\ImageVerifier;
use App\Service\LinkCard\LinkCardSweep;
use App\Service\Maintenance\FollowUpFetchSweeps;
use App\Service\Transcript\TranscriptSource\TranscriptSourceInterface;
use App\Service\Transcript\TranscriptSweep;
use App\Tests\DbTestCase;
use App\Tests\Support\PngImageFactory;
use App\Tests\Support\RenditionProbes;
use App\Tests\Support\ScriptedTranscriptSource;
use App\Tests\Support\StubGuardedImageDownloader;
use App\Tests\Support\StubProbedLadders;
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

        $report = $this->sweeps($clock, new StubGuardedImageDownloader())->sweep($clock->now()->modify('+9 seconds'));

        self::assertSame(0, $report->linkCards->considered);
        self::assertSame(2, $report->transcripts->claimed);
        self::assertCount(2, $source->fetchedUrls);
    }

    public function testADeadlineUnderOneFetchAwayFetchesNothing(): void
    {
        (new TranscriptFixtures($this->entityManager))->pendingTranscripts(1);
        $clock = new MockClock('2026-10-27 12:00:00', 'UTC');

        $report = $this->sweeps($clock, new StubGuardedImageDownloader())->sweep($clock->now()->modify('+3 seconds'));

        self::assertSame(0, $report->transcripts->claimed);
    }

    public function testImageVerificationSpendsHalfOfWhatIsLeft(): void
    {
        self::assertSame(1, $this->imagesMeasuredWithinSecondsOfTheDeadline(40));
    }

    public function testImageVerificationTakesASecondImageOnceHalfOfWhatIsLeftOutlastsTheFirst(): void
    {
        self::assertSame(2, $this->imagesMeasuredWithinSecondsOfTheDeadline(70));
    }

    public function testImageVerificationStopsAtItsOwnCapInALongWindow(): void
    {
        self::assertSame(2, $this->imagesMeasuredWithinSecondsOfTheDeadline(1000));
    }

    private function imagesMeasuredWithinSecondsOfTheDeadline(int $seconds): int
    {
        $clock = new MockClock('2026-10-27 12:00:00', 'UTC');
        $this->pendingImages($clock, 3);
        $downloader = new StubGuardedImageDownloader();
        $downloader->willAlwaysReturn(PngImageFactory::bytes(600, 400));
        $downloader->takingSecondsEach($clock, 30);

        $report = $this->sweeps($clock, $downloader)->sweep($clock->now()->modify(\sprintf('+%d seconds', $seconds)));

        return $report->imageVerification->measured;
    }

    private function pendingImages(MockClock $clock, int $count): void
    {
        $feed = new Feed('https://example.test/feed.xml');
        $this->entityManager->persist($feed);
        for ($index = 0; $index < $count; $index++) {
            $entry = new Entry(
                $feed,
                'image-' . $index,
                'https://example.test/image-' . $index,
                'Title ' . $index,
                $clock->now()->modify('-1 hour'),
                $clock->now()->modify('-2 hours'),
            );
            $entry->getImage()->storePending('https://i/image-' . $index . '.png', null, null);
            $this->entityManager->persist($entry);
        }
        $this->entityManager->flush();
    }

    private function sweeps(MockClock $clock, StubGuardedImageDownloader $downloader): FollowUpFetchSweeps
    {
        $linkCards = self::getContainer()->get(LinkCardSweep::class);
        self::assertInstanceOf(LinkCardSweep::class, $linkCards);
        $transcripts = self::getContainer()->get(TranscriptSweep::class);
        self::assertInstanceOf(TranscriptSweep::class, $transcripts);

        $images = $this->imageVerificationSweep($clock, $downloader);

        return new FollowUpFetchSweeps($images, $linkCards, $transcripts, $clock);
    }

    private function imageVerificationSweep(
        MockClock $clock,
        StubGuardedImageDownloader $downloader,
    ): ImageVerificationSweep {
        $pendingVerifications = self::getContainer()->get(PendingImageVerificationRepository::class);
        self::assertInstanceOf(PendingImageVerificationRepository::class, $pendingVerifications);
        $verifier = new ImageVerifier(
            $downloader,
            new NaiveUtcClock($clock),
            RenditionProbes::none(),
            new StubProbedLadders(),
        );

        return new ImageVerificationSweep($pendingVerifications, $verifier, $this->entityManager, $clock);
    }
}
