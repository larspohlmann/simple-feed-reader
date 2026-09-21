<?php

declare(strict_types=1);

namespace App\Tests\Service\Image;

use App\Entity\Entry;
use App\Entity\Feed;
use App\Repository\PendingImageVerificationRepository;
use App\Service\Catalog\Exception\FaviconRejectedException;
use App\Service\Catalog\Exception\FaviconUnavailableException;
use App\Service\Clock\NaiveUtcClock;
use App\Service\Image\ImageVerificationSweep;
use App\Service\Image\ImageVerifier;
use App\Tests\DbTestCase;
use App\Tests\Support\PngImageFactory;
use App\Tests\Support\StubFaviconFetcher;
use Symfony\Component\Clock\MockClock;

final class ImageVerificationSweepTest extends DbTestCase
{
    private function sweep(StubFaviconFetcher $fetcher): ImageVerificationSweep
    {
        /** @var PendingImageVerificationRepository $repository */
        $repository = self::getContainer()->get(PendingImageVerificationRepository::class);
        $verifier = new ImageVerifier($fetcher, new NaiveUtcClock(new MockClock('2026-09-21 12:00:00')));

        return new ImageVerificationSweep($repository, $verifier, $this->em);
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
        $this->em->persist($entry);
    }

    public function testMeasuresDropsAndReportsCounts(): void
    {
        $feed = new Feed('https://example.test/feed.xml');
        $this->em->persist($feed);
        $this->pendingEntry($feed, 'good', 'https://i/good.png');
        $this->pendingEntry($feed, 'beacon', 'https://i/beacon.png');
        $this->pendingEntry($feed, 'rejected', 'https://i/rejected.png');
        $this->em->flush();

        $fetcher = new StubFaviconFetcher();
        $fetcher->willReturnBytes('https://i/good.png', PngImageFactory::bytes(600, 400));
        $fetcher->willReturnBytes('https://i/beacon.png', PngImageFactory::bytes(1, 1));
        $fetcher->willFail('https://i/rejected.png', new FaviconRejectedException('Icon responded 403.'));

        $report = $this->sweep($fetcher)->verifyDue()->toArray();

        self::assertSame(1, $report['measured']);
        self::assertSame(1, $report['kept']);
        self::assertSame(1, $report['dropped']);
        self::assertSame(0, $report['retried']);

        $this->em->clear();
        $good = $this->em->getRepository(Entry::class)->findOneBy(['guid' => 'good']);
        self::assertNotNull($good);
        self::assertSame(600, $good->getImageWidth());
        self::assertNotNull($good->getImage()->getCheckedAt());
        $beacon = $this->em->getRepository(Entry::class)->findOneBy(['guid' => 'beacon']);
        self::assertNotNull($beacon);
        self::assertNull($beacon->getImageUrl());
        $rejected = $this->em->getRepository(Entry::class)->findOneBy(['guid' => 'rejected']);
        self::assertNotNull($rejected);
        self::assertSame('https://i/rejected.png', $rejected->getImageUrl());
        self::assertNotNull($rejected->getImage()->getCheckedAt());
    }

    public function testCountsARetriedImageAndLeavesItPending(): void
    {
        $feed = new Feed('https://example.test/feed.xml');
        $this->em->persist($feed);
        $this->pendingEntry($feed, 'gone', 'https://i/gone.png');
        $this->em->flush();

        $fetcher = new StubFaviconFetcher();
        $fetcher->willFail('https://i/gone.png', new FaviconUnavailableException('timeout'));

        $report = $this->sweep($fetcher)->verifyDue()->toArray();

        self::assertSame(0, $report['measured']);
        self::assertSame(0, $report['dropped']);
        self::assertSame(1, $report['retried']);

        $this->em->clear();
        $gone = $this->em->getRepository(Entry::class)->findOneBy(['guid' => 'gone']);
        self::assertNotNull($gone);
        self::assertSame('https://i/gone.png', $gone->getImageUrl());
        self::assertNull($gone->getImage()->getCheckedAt());
    }

    public function testAVerifiedImageLeavesTheQueue(): void
    {
        $feed = new Feed('https://example.test/feed.xml');
        $this->em->persist($feed);
        $this->pendingEntry($feed, 'good', 'https://i/good.png');
        $this->em->flush();

        $fetcher = new StubFaviconFetcher();
        $fetcher->willAlwaysReturn(PngImageFactory::bytes(600, 400));
        $this->sweep($fetcher)->verifyDue();
        $this->em->clear();

        /** @var PendingImageVerificationRepository $repository */
        $repository = self::getContainer()->get(PendingImageVerificationRepository::class);
        self::assertCount(0, $repository->findPendingImageVerification(50));
    }
}
