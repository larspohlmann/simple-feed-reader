<?php

declare(strict_types=1);

namespace App\Tests\Service\Maintenance\MaintenanceSweep;

use App\Service\Image\Model\ImageVerificationReportModel;
use App\Entity\Feed;
use App\Service\Maintenance\MaintenanceSweep\ImageVerificationTickSweep;
use App\Tests\DbTestCase;
use App\Tests\Support\ImageVerificationSweepWiring;
use App\Tests\Support\PendingImageFixtures;
use App\Tests\Support\PngImageFactory;
use App\Tests\Support\StubGuardedImageDownloader;
use Symfony\Component\Clock\MockClock;

final class ImageVerificationTickSweepTest extends DbTestCase
{
    public function testSpendsHalfOfWhatIsLeft(): void
    {
        self::assertSame(1, $this->reportWithinSecondsOfTheDeadline(40)->measured);
    }

    public function testStopsAtItsOwnCapInALongWindow(): void
    {
        self::assertSame(2, $this->reportWithinSecondsOfTheDeadline(1000)->measured);
    }

    public function testMeasuresNothingOnceTheDeadlineHasPassed(): void
    {
        self::assertSame(0, $this->reportWithinSecondsOfTheDeadline(-1)->measured);
    }

    private function reportWithinSecondsOfTheDeadline(int $seconds): ImageVerificationReportModel
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
        $sweep = new ImageVerificationTickSweep(
            ImageVerificationSweepWiring::build(self::getContainer(), $this->entityManager, $downloader, $clock),
            $clock,
        );

        return $sweep->run($clock->now()->modify(\sprintf('%+d seconds', $seconds)));
    }
}
