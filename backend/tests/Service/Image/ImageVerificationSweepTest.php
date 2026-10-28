<?php

declare(strict_types=1);

namespace App\Tests\Service\Image;

use App\Entity\Entry;
use App\Entity\Feed;
use App\Repository\PendingImageVerificationRepository;
use App\Service\Image\Exception\GuardedImageRejectedException;
use App\Service\Image\Exception\GuardedImageUnavailableException;
use App\Service\Clock\NaiveUtcClock;
use App\Service\Image\ImageVerificationSweep;
use App\Service\Image\ImageVerifier;
use App\Tests\DbTestCase;
use App\Tests\Support\RenditionProbes;
use App\Tests\Support\PngImageFactory;
use App\Tests\Support\StubGuardedImageDownloader;
use Symfony\Component\Clock\MockClock;

final class ImageVerificationSweepTest extends DbTestCase
{
    private function sweep(StubGuardedImageDownloader $downloader): ImageVerificationSweep
    {
        /** @var PendingImageVerificationRepository $repository */
        $repository = self::getContainer()->get(PendingImageVerificationRepository::class);
        $verifier = new ImageVerifier(
            $downloader,
            new NaiveUtcClock(new MockClock('2026-09-21 12:00:00')),
            RenditionProbes::none(),
        );

        return new ImageVerificationSweep($repository, $verifier, $this->entityManager);
    }

    private function pendingEntry(Feed $feed, string $guid, string $imageUrl): void
    {
        $entry = new Entry(
            $feed,
            $guid,
            'https://example.test/' . $guid,
            'Title ' . $guid,
            new \DateTimeImmutable('2026-09-21 06:00:00'),
            new \DateTimeImmutable('2026-09-21 05:00:00'),
        );
        $entry->getImage()->storePending($imageUrl, null, null);
        $this->entityManager->persist($entry);
    }

    public function testMeasuresDropsAndReportsCounts(): void
    {
        $feed = new Feed('https://example.test/feed.xml');
        $this->entityManager->persist($feed);
        $this->pendingEntry($feed, 'good', 'https://i/good.png');
        $this->pendingEntry($feed, 'beacon', 'https://i/beacon.png');
        $this->pendingEntry($feed, 'rejected', 'https://i/rejected.png');
        $this->entityManager->flush();

        $downloader = new StubGuardedImageDownloader();
        $downloader->willReturnBytes('https://i/good.png', PngImageFactory::bytes(600, 400));
        $downloader->willReturnBytes('https://i/beacon.png', PngImageFactory::bytes(1, 1));
        $downloader->willFail('https://i/rejected.png', new GuardedImageRejectedException('Image responded 403.'));

        $report = $this->sweep($downloader)->verifyDue();

        self::assertSame(1, $report->measured);
        self::assertSame(1, $report->kept);
        self::assertSame(1, $report->dropped);
        self::assertSame(0, $report->retried);

        $this->entityManager->clear();
        $good = $this->entityManager->getRepository(Entry::class)->findOneBy(['guid.value' => 'good']);
        self::assertNotNull($good);
        self::assertSame(600, $good->getImageWidth());
        self::assertNotNull($good->getImage()->getCheckedAt());
        $beacon = $this->entityManager->getRepository(Entry::class)->findOneBy(['guid.value' => 'beacon']);
        self::assertNotNull($beacon);
        self::assertNull($beacon->getImageUrl());
        $rejected = $this->entityManager->getRepository(Entry::class)->findOneBy(['guid.value' => 'rejected']);
        self::assertNotNull($rejected);
        self::assertSame('https://i/rejected.png', $rejected->getImageUrl());
        self::assertNotNull($rejected->getImage()->getCheckedAt());
    }

    public function testCountsARetriedImageAndLeavesItPending(): void
    {
        $feed = new Feed('https://example.test/feed.xml');
        $this->entityManager->persist($feed);
        $this->pendingEntry($feed, 'gone', 'https://i/gone.png');
        $this->entityManager->flush();

        $downloader = new StubGuardedImageDownloader();
        $downloader->willFail('https://i/gone.png', new GuardedImageUnavailableException('timeout'));

        $report = $this->sweep($downloader)->verifyDue();

        self::assertSame(0, $report->measured);
        self::assertSame(0, $report->dropped);
        self::assertSame(1, $report->retried);

        $this->entityManager->clear();
        $gone = $this->entityManager->getRepository(Entry::class)->findOneBy(['guid.value' => 'gone']);
        self::assertNotNull($gone);
        self::assertSame('https://i/gone.png', $gone->getImageUrl());
        self::assertNull($gone->getImage()->getCheckedAt());
    }

    public function testAQueuedRowWithoutAUrlIsSettledNotReturnedForever(): void
    {
        $feed = new Feed('https://example.test/feed.xml');
        $this->entityManager->persist($feed);
        $this->pendingEntry($feed, 'poison', 'https://i/poison.png');
        $this->entityManager->flush();
        $this->entityManager->getConnection()->executeStatement(
            'UPDATE entry SET image_url = NULL WHERE guid = ?',
            ['poison'],
        );
        $this->entityManager->clear();

        $report = $this->sweep(new StubGuardedImageDownloader())->verifyDue();

        self::assertSame(1, $report->dropped);
        /** @var PendingImageVerificationRepository $repository */
        $repository = self::getContainer()->get(PendingImageVerificationRepository::class);
        self::assertCount(0, $repository->findPendingImageVerification(50));
    }

    public function testAVerifiedImageLeavesTheQueue(): void
    {
        $feed = new Feed('https://example.test/feed.xml');
        $this->entityManager->persist($feed);
        $this->pendingEntry($feed, 'good', 'https://i/good.png');
        $this->entityManager->flush();

        $downloader = new StubGuardedImageDownloader();
        $downloader->willAlwaysReturn(PngImageFactory::bytes(600, 400));
        $this->sweep($downloader)->verifyDue();
        $this->entityManager->clear();

        /** @var PendingImageVerificationRepository $repository */
        $repository = self::getContainer()->get(PendingImageVerificationRepository::class);
        self::assertCount(0, $repository->findPendingImageVerification(50));
    }
}
