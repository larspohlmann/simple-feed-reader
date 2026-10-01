<?php

declare(strict_types=1);

namespace App\Tests\Service\Worker;

use App\Entity\Entry;
use App\Entity\Feed;
use App\Repository\PendingImageVerificationRepository;
use App\Service\Clock\NaiveUtcClock;
use App\Service\Image\ImageVerificationSweep;
use App\Service\Image\ImageVerifier;
use App\Service\Worker\Handler\VerifyPendingImagesHandler;
use App\Service\Worker\Message\VerifyPendingImages;
use App\Tests\DbTestCase;
use App\Tests\Support\PngImageFactory;
use App\Tests\Support\RecordingLogger;
use App\Tests\Support\StubFaviconFetcher;
use Symfony\Component\Clock\MockClock;

final class VerifyPendingImagesHandlerTest extends DbTestCase
{
    public function testFiringVerifiesAPendingImageAndLogsTheReport(): void
    {
        $feed = new Feed('https://example.test/feed.xml');
        $this->entityManager->persist($feed);
        $entry = new Entry(
            $feed,
            'pending',
            'https://example.test/pending',
            'Title',
            new \DateTimeImmutable('2026-10-01 06:00:00'),
            new \DateTimeImmutable('2026-10-01 05:00:00'),
        );
        $entry->getImage()->storePending('https://i/pending.png', 250, 250);
        $this->entityManager->persist($entry);
        $this->entityManager->flush();
        $fetcher = new StubFaviconFetcher();
        $fetcher->willReturnBytes('https://i/pending.png', PngImageFactory::bytes(600, 400));
        $logger = new RecordingLogger();

        $this->handler($fetcher, $logger)->__invoke(new VerifyPendingImages());

        self::assertCount(1, $logger->records);
        self::assertSame('info', $logger->records[0]['level']);
        self::assertSame(
            ['measured' => 1, 'kept' => 0, 'dropped' => 0, 'retried' => 0],
            $logger->records[0]['context']['report'],
        );
        $this->entityManager->clear();
        $verified = $this->entityManager->getRepository(Entry::class)->findOneBy(['guid' => 'pending']);
        self::assertNotNull($verified);
        self::assertSame(600, $verified->getImageWidth());
    }

    private function handler(StubFaviconFetcher $fetcher, RecordingLogger $logger): VerifyPendingImagesHandler
    {
        /** @var PendingImageVerificationRepository $pendingVerifications */
        $pendingVerifications = self::getContainer()->get(PendingImageVerificationRepository::class);
        $verifier = new ImageVerifier($fetcher, new NaiveUtcClock(new MockClock('2026-10-01 07:00:00')));

        return new VerifyPendingImagesHandler(
            new ImageVerificationSweep($pendingVerifications, $verifier, $this->entityManager),
            $logger,
        );
    }
}
