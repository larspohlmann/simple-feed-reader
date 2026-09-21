<?php

declare(strict_types=1);

namespace App\Tests\Service\Image;

use App\Entity\Entry;
use App\Entity\EntryMedium;
use App\Entity\Feed;
use App\Service\Catalog\Exception\FaviconRejectedException;
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

    private function verifier(StubFaviconFetcher $fetcher): ImageVerifier
    {
        return new ImageVerifier($fetcher, new NaiveUtcClock(new MockClock('2026-09-21 12:00:00')));
    }

    public function testMeasuresAndStampsAReachableImage(): void
    {
        $fetcher = new StubFaviconFetcher();
        $fetcher->willReturnBytes('https://i/ok.png', PngImageFactory::bytes(600, 400));
        $entry = $this->pendingImage('https://i/ok.png');

        $outcome = $this->verifier($fetcher)->verify($entry);

        self::assertSame(ImageVerifyOutcome::Measured, $outcome);
        self::assertSame(600, $entry->getImage()->getWidth());
        self::assertSame(400, $entry->getImage()->getHeight());
        self::assertNotNull($entry->getImage()->getCheckedAt());
    }

    public function testDropsABeacon(): void
    {
        $fetcher = new StubFaviconFetcher();
        $fetcher->willReturnBytes('https://i/pixel.png', PngImageFactory::bytes(1, 1));
        $entry = $this->pendingImage('https://i/pixel.png');

        $outcome = $this->verifier($fetcher)->verify($entry);

        self::assertSame(ImageVerifyOutcome::Dropped, $outcome);
        self::assertNull($entry->getImage()->getUrl());
        self::assertEquals(new \DateTimeImmutable('2026-09-21 12:00:00'), $entry->getImage()->getCheckedAt());
    }

    public function testDroppingTheImageAlsoRemovesItFromTheMediaList(): void
    {
        $fetcher = new StubFaviconFetcher();
        $fetcher->willReturnBytes('https://i/pixel.png', PngImageFactory::bytes(1, 1));
        $entry = $this->pendingImage('https://i/pixel.png');
        $entry->setMedia([
            new EntryMedium('https://i/pixel.png', 'image'),
            new EntryMedium('https://i/other.jpg', 'image'),
        ], []);

        $outcome = $this->verifier($fetcher)->verify($entry);

        self::assertSame(ImageVerifyOutcome::Dropped, $outcome);
        $media = $entry->getMedia();
        self::assertCount(1, $media);
        self::assertSame('https://i/other.jpg', $media[0]->url);
    }

    public function testKeepsAThumbnailWithOneEdgeOverTheCeiling(): void
    {
        $fetcher = new StubFaviconFetcher();
        $fetcher->willReturnBytes('https://i/thumb.png', PngImageFactory::bytes(134, 76));
        $entry = $this->pendingImage('https://i/thumb.png');

        $outcome = $this->verifier($fetcher)->verify($entry);

        self::assertSame(ImageVerifyOutcome::Measured, $outcome);
        self::assertSame('https://i/thumb.png', $entry->getImage()->getUrl());
        self::assertSame(134, $entry->getImage()->getWidth());
    }

    public function testRetriesOnAFetchFailureBelowTheCap(): void
    {
        $fetcher = new StubFaviconFetcher();
        $fetcher->willFail('https://i/gone.png', new FaviconUnavailableException('timeout'));
        $entry = $this->pendingImage('https://i/gone.png');

        $outcome = $this->verifier($fetcher)->verify($entry);

        self::assertSame(ImageVerifyOutcome::Retried, $outcome);
        self::assertSame('https://i/gone.png', $entry->getImage()->getUrl());
        self::assertSame(1, $entry->getImage()->getVerifyAttempts());
        self::assertNull($entry->getImage()->getCheckedAt());
    }

    public function testDropsAfterTheRetryCapIsReached(): void
    {
        $fetcher = new StubFaviconFetcher();
        $fetcher->willFail('https://i/gone.png', new FaviconUnavailableException('timeout'));
        $entry = $this->pendingImage('https://i/gone.png');
        $verifier = $this->verifier($fetcher);

        self::assertSame(ImageVerifyOutcome::Retried, $verifier->verify($entry));
        self::assertSame(ImageVerifyOutcome::Retried, $verifier->verify($entry));
        $outcome = $verifier->verify($entry);

        self::assertSame(ImageVerifyOutcome::Dropped, $outcome);
        self::assertNull($entry->getImage()->getUrl());
        self::assertEquals(new \DateTimeImmutable('2026-09-21 12:00:00'), $entry->getImage()->getCheckedAt());
    }

    public function testKeepsAnImageTheFetcherRejected(): void
    {
        $fetcher = new StubFaviconFetcher();
        $fetcher->willFail('https://i/rejected.png', new FaviconRejectedException('Icon responded 403.'));
        $entry = $this->pendingImage('https://i/rejected.png', 640, 360);

        $outcome = $this->verifier($fetcher)->verify($entry);

        self::assertSame(ImageVerifyOutcome::Kept, $outcome);
        self::assertSame('https://i/rejected.png', $entry->getImage()->getUrl());
        self::assertSame(640, $entry->getImage()->getWidth());
        self::assertEquals(new \DateTimeImmutable('2026-09-21 12:00:00'), $entry->getImage()->getCheckedAt());
        self::assertSame(0, $entry->getImage()->getVerifyAttempts());
    }

    public function testARejectionAfterFailedProbesStillKeepsTheImage(): void
    {
        $fetcher = new StubFaviconFetcher();
        $fetcher->willFail('https://i/gone.png', new FaviconUnavailableException('timeout'));
        $entry = $this->pendingImage('https://i/gone.png');
        $verifier = $this->verifier($fetcher);

        self::assertSame(ImageVerifyOutcome::Retried, $verifier->verify($entry));
        self::assertSame(ImageVerifyOutcome::Retried, $verifier->verify($entry));

        $fetcher->willFail('https://i/gone.png', new FaviconRejectedException('Icon responded 403.'));
        $outcome = $verifier->verify($entry);

        self::assertSame(ImageVerifyOutcome::Kept, $outcome);
    }

    public function testTreatsUndecodableBytesAsAFailedProbe(): void
    {
        $fetcher = new StubFaviconFetcher();
        $fetcher->willReturnBytes('https://i/broken.png', 'not an image');
        $entry = $this->pendingImage('https://i/broken.png');

        $outcome = $this->verifier($fetcher)->verify($entry);

        self::assertSame(ImageVerifyOutcome::Retried, $outcome);
        self::assertSame(1, $entry->getImage()->getVerifyAttempts());
    }
}
