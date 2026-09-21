<?php

declare(strict_types=1);

namespace App\Tests\Service\Image;

use App\Entity\EntryImage;
use App\Service\Catalog\Exception\FaviconUnavailableException;
use App\Service\Clock\NaiveUtcClock;
use App\Service\Image\ImageVerifier;
use App\Service\Image\ImageVerifyOutcome;
use App\Tests\Support\PngImageFactory;
use App\Tests\Support\StubFaviconFetcher;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

final class ImageVerifierTest extends TestCase
{
    private function pendingImage(string $url): EntryImage
    {
        $image = new EntryImage();
        $image->storePending($url, null, null);

        return $image;
    }

    private function verifier(StubFaviconFetcher $fetcher): ImageVerifier
    {
        return new ImageVerifier($fetcher, new NaiveUtcClock(new MockClock('2026-09-21 12:00:00')));
    }

    public function testMeasuresAndStampsAReachableImage(): void
    {
        $fetcher = new StubFaviconFetcher();
        $fetcher->willReturnBytes('https://i/ok.png', PngImageFactory::bytes(600, 400));
        $image = $this->pendingImage('https://i/ok.png');

        $outcome = $this->verifier($fetcher)->verify($image);

        self::assertSame(ImageVerifyOutcome::Measured, $outcome);
        self::assertSame(600, $image->getWidth());
        self::assertSame(400, $image->getHeight());
        self::assertNotNull($image->getCheckedAt());
    }

    public function testDropsABeacon(): void
    {
        $fetcher = new StubFaviconFetcher();
        $fetcher->willReturnBytes('https://i/pixel.png', PngImageFactory::bytes(1, 1));
        $image = $this->pendingImage('https://i/pixel.png');

        $outcome = $this->verifier($fetcher)->verify($image);

        self::assertSame(ImageVerifyOutcome::Dropped, $outcome);
        self::assertNull($image->getUrl());
        self::assertEquals(new \DateTimeImmutable('2026-09-21 12:00:00'), $image->getCheckedAt());
    }

    public function testKeepsAThumbnailWithOneEdgeOverTheCeiling(): void
    {
        $fetcher = new StubFaviconFetcher();
        $fetcher->willReturnBytes('https://i/thumb.png', PngImageFactory::bytes(134, 76));
        $image = $this->pendingImage('https://i/thumb.png');

        $outcome = $this->verifier($fetcher)->verify($image);

        self::assertSame(ImageVerifyOutcome::Measured, $outcome);
        self::assertSame('https://i/thumb.png', $image->getUrl());
        self::assertSame(134, $image->getWidth());
    }

    public function testRetriesOnAFetchFailureBelowTheCap(): void
    {
        $fetcher = new StubFaviconFetcher();
        $fetcher->willFail('https://i/gone.png', new FaviconUnavailableException('timeout'));
        $image = $this->pendingImage('https://i/gone.png');

        $outcome = $this->verifier($fetcher)->verify($image);

        self::assertSame(ImageVerifyOutcome::Retried, $outcome);
        self::assertSame('https://i/gone.png', $image->getUrl());
        self::assertSame(1, $image->getVerifyAttempts());
        self::assertNull($image->getCheckedAt());
    }

    public function testDropsAfterTheRetryCapIsReached(): void
    {
        $fetcher = new StubFaviconFetcher();
        $fetcher->willFail('https://i/gone.png', new FaviconUnavailableException('timeout'));
        $image = $this->pendingImage('https://i/gone.png');
        $verifier = $this->verifier($fetcher);

        self::assertSame(ImageVerifyOutcome::Retried, $verifier->verify($image));
        self::assertSame(ImageVerifyOutcome::Retried, $verifier->verify($image));
        $outcome = $verifier->verify($image);

        self::assertSame(ImageVerifyOutcome::Dropped, $outcome);
        self::assertNull($image->getUrl());
        self::assertEquals(new \DateTimeImmutable('2026-09-21 12:00:00'), $image->getCheckedAt());
    }

    public function testTreatsUndecodableBytesAsAFailedProbe(): void
    {
        $fetcher = new StubFaviconFetcher();
        $fetcher->willReturnBytes('https://i/broken.png', 'not an image');
        $image = $this->pendingImage('https://i/broken.png');

        $outcome = $this->verifier($fetcher)->verify($image);

        self::assertSame(ImageVerifyOutcome::Retried, $outcome);
        self::assertSame(1, $image->getVerifyAttempts());
    }
}
