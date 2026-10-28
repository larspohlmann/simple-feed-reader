<?php

declare(strict_types=1);

namespace App\Tests\Service\Image;

use App\Entity\Entry;
use App\Entity\EntryMedium;
use App\Entity\Feed;
use App\Entity\ImageRendition;
use App\Service\Clock\NaiveUtcClock;
use App\Service\Image\Exception\GuardedImageRejectedException;
use App\Service\Image\Exception\GuardedImageUnavailableException;
use App\Service\Image\ImageVerifier;
use App\Service\Image\Model\ImageVerifyOutcome;
use App\Tests\Support\PngImageFactory;
use App\Tests\Support\RenditionProbes;
use App\Tests\Support\StubGuardedImageDownloader;
use App\Tests\Support\StubGuardedImageProbe;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

final class ImageVerifierTest extends TestCase
{
    private function pendingImage(string $url, ?int $width = null, ?int $height = null): Entry
    {
        $entry = $this->entry();
        $entry->getImage()->storePending($url, $width, $height);

        return $entry;
    }

    private function entry(): Entry
    {
        $feed = new Feed('https://example.test/feed.xml');

        return new Entry(
            $feed,
            'guid',
            'https://example.test/entry',
            'Title',
            new \DateTimeImmutable('2026-09-21 06:00:00'),
            new \DateTimeImmutable('2026-09-21 05:00:00'),
        );
    }

    private function verifier(
        StubGuardedImageDownloader $downloader,
        ?StubGuardedImageProbe $probe = null,
    ): ImageVerifier {
        return new ImageVerifier(
            $downloader,
            new NaiveUtcClock(new MockClock('2026-09-21 12:00:00')),
            RenditionProbes::over($probe ?? new StubGuardedImageProbe()),
        );
    }

    public function testAMeasuredLeadGainsItsProbedRungsBesideTheDeclaredOnes(): void
    {
        $lead = 'https://i/wp-content/uploads/a.png';
        $downloader = new StubGuardedImageDownloader();
        $downloader->willReturnBytes($lead, PngImageFactory::bytes(1600, 900));
        $probe = new StubGuardedImageProbe();
        $probe->willExist('https://i/wp-content/uploads/a-768x432.png');
        $entry = $this->pendingImage($lead);
        $entry->getImage()->storeRenditions([new ImageRendition('https://i/wp-content/uploads/a-300x169.png', 300)]);

        $this->verifier($downloader, $probe)->verify($entry);

        self::assertEquals([
            new ImageRendition('https://i/wp-content/uploads/a-300x169.png', 300),
            new ImageRendition('https://i/wp-content/uploads/a-768x432.png', 768),
        ], $entry->getImage()->getRenditions());
    }

    public function testADroppedImageIsNeverProbed(): void
    {
        $downloader = new StubGuardedImageDownloader();
        $downloader->willReturnBytes('https://i/pixel.png', PngImageFactory::bytes(1, 1));
        $probe = new StubGuardedImageProbe();

        $this->verifier($downloader, $probe)->verify($this->pendingImage('https://i/pixel.png'));

        self::assertSame([], $probe->requestedUrls());
    }

    public function testAFailedProbeLeavesTheOutcomeMeasured(): void
    {
        $downloader = new StubGuardedImageDownloader();
        $downloader->willReturnBytes('https://i/wp-content/uploads/a.png', PngImageFactory::bytes(1600, 900));

        $outcome = $this->verifier($downloader)->verify($this->pendingImage('https://i/wp-content/uploads/a.png'));

        self::assertSame(ImageVerifyOutcome::Measured, $outcome);
    }

    public function testARefusedOrRetriedDownloadMakesNoProbeRequest(): void
    {
        $downloader = new StubGuardedImageDownloader();
        $downloader->willFail('https://i/rejected.png', new GuardedImageRejectedException('Image responded 403.'));
        $downloader->willFail('https://i/gone.png', new GuardedImageUnavailableException('timeout'));
        $probe = new StubGuardedImageProbe();
        $verifier = $this->verifier($downloader, $probe);

        $verifier->verify($this->pendingImage('https://i/rejected.png'));
        $verifier->verify($this->pendingImage('https://i/gone.png'));

        self::assertSame([], $probe->requestedUrls());
    }

    public function testMeasuresAndStampsAReachableImage(): void
    {
        $downloader = new StubGuardedImageDownloader();
        $downloader->willReturnBytes('https://i/ok.png', PngImageFactory::bytes(600, 400));
        $entry = $this->pendingImage('https://i/ok.png');

        $outcome = $this->verifier($downloader)->verify($entry);

        self::assertSame(ImageVerifyOutcome::Measured, $outcome);
        self::assertSame(600, $entry->getImage()->getWidth());
        self::assertSame(400, $entry->getImage()->getHeight());
        self::assertNotNull($entry->getImage()->getCheckedAt());
    }

    public function testDropsABeacon(): void
    {
        $downloader = new StubGuardedImageDownloader();
        $downloader->willReturnBytes('https://i/pixel.png', PngImageFactory::bytes(1, 1));
        $entry = $this->pendingImage('https://i/pixel.png');

        $outcome = $this->verifier($downloader)->verify($entry);

        self::assertSame(ImageVerifyOutcome::Dropped, $outcome);
        self::assertNull($entry->getImage()->getUrl());
        self::assertEquals(new \DateTimeImmutable('2026-09-21 12:00:00'), $entry->getImage()->getCheckedAt());
    }

    public function testDroppingTheImageAlsoRemovesItFromTheMediaList(): void
    {
        $downloader = new StubGuardedImageDownloader();
        $downloader->willReturnBytes('https://i/pixel.png', PngImageFactory::bytes(1, 1));
        $entry = $this->pendingImage('https://i/pixel.png');
        $entry->setMedia([
            new EntryMedium('https://i/pixel.png', 'image'),
            new EntryMedium('https://i/other.jpg', 'image'),
        ], []);

        $outcome = $this->verifier($downloader)->verify($entry);

        self::assertSame(ImageVerifyOutcome::Dropped, $outcome);
        $media = $entry->getMedia();
        self::assertCount(1, $media);
        self::assertSame('https://i/other.jpg', $media[0]->url);
    }

    public function testKeepsAThumbnailWithOneEdgeOverTheCeiling(): void
    {
        $downloader = new StubGuardedImageDownloader();
        $downloader->willReturnBytes('https://i/thumb.png', PngImageFactory::bytes(134, 76));
        $entry = $this->pendingImage('https://i/thumb.png');

        $outcome = $this->verifier($downloader)->verify($entry);

        self::assertSame(ImageVerifyOutcome::Measured, $outcome);
        self::assertSame('https://i/thumb.png', $entry->getImage()->getUrl());
        self::assertSame(134, $entry->getImage()->getWidth());
    }

    public function testRetriesOnAFetchFailureBelowTheCap(): void
    {
        $downloader = new StubGuardedImageDownloader();
        $downloader->willFail('https://i/gone.png', new GuardedImageUnavailableException('timeout'));
        $entry = $this->pendingImage('https://i/gone.png');

        $outcome = $this->verifier($downloader)->verify($entry);

        self::assertSame(ImageVerifyOutcome::Retried, $outcome);
        self::assertSame('https://i/gone.png', $entry->getImage()->getUrl());
        self::assertSame(1, $entry->getImage()->getVerifyAttempts());
        self::assertNull($entry->getImage()->getCheckedAt());
    }

    public function testDropsAfterTheRetryCapIsReached(): void
    {
        $downloader = new StubGuardedImageDownloader();
        $downloader->willFail('https://i/gone.png', new GuardedImageUnavailableException('timeout'));
        $entry = $this->pendingImage('https://i/gone.png');
        $verifier = $this->verifier($downloader);

        self::assertSame(ImageVerifyOutcome::Retried, $verifier->verify($entry));
        self::assertSame(ImageVerifyOutcome::Retried, $verifier->verify($entry));
        $outcome = $verifier->verify($entry);

        self::assertSame(ImageVerifyOutcome::Dropped, $outcome);
        self::assertNull($entry->getImage()->getUrl());
        self::assertEquals(new \DateTimeImmutable('2026-09-21 12:00:00'), $entry->getImage()->getCheckedAt());
    }

    public function testKeepsAnImageTheDownloaderRejected(): void
    {
        $downloader = new StubGuardedImageDownloader();
        $downloader->willFail('https://i/rejected.png', new GuardedImageRejectedException('Image responded 403.'));
        $entry = $this->pendingImage('https://i/rejected.png', 640, 360);

        $outcome = $this->verifier($downloader)->verify($entry);

        self::assertSame(ImageVerifyOutcome::Kept, $outcome);
        self::assertSame('https://i/rejected.png', $entry->getImage()->getUrl());
        self::assertSame(640, $entry->getImage()->getWidth());
        self::assertEquals(new \DateTimeImmutable('2026-09-21 12:00:00'), $entry->getImage()->getCheckedAt());
        self::assertSame(0, $entry->getImage()->getVerifyAttempts());
    }

    public function testARejectionAfterFailedProbesStillKeepsTheImage(): void
    {
        $downloader = new StubGuardedImageDownloader();
        $downloader->willFail('https://i/gone.png', new GuardedImageUnavailableException('timeout'));
        $entry = $this->pendingImage('https://i/gone.png');
        $verifier = $this->verifier($downloader);

        self::assertSame(ImageVerifyOutcome::Retried, $verifier->verify($entry));
        self::assertSame(ImageVerifyOutcome::Retried, $verifier->verify($entry));

        $downloader->willFail('https://i/gone.png', new GuardedImageRejectedException('Image responded 403.'));
        $outcome = $verifier->verify($entry);

        self::assertSame(ImageVerifyOutcome::Kept, $outcome);
    }

    public function testTreatsUndecodableBytesAsAFailedProbe(): void
    {
        $downloader = new StubGuardedImageDownloader();
        $downloader->willReturnBytes('https://i/broken.png', 'not an image');
        $entry = $this->pendingImage('https://i/broken.png');

        $outcome = $this->verifier($downloader)->verify($entry);

        self::assertSame(ImageVerifyOutcome::Retried, $outcome);
        self::assertSame(1, $entry->getImage()->getVerifyAttempts());
    }
}
