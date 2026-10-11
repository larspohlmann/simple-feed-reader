<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Entity\CatalogCategory;
use App\Entity\CatalogFeed;
use App\Service\Fetch\FaviconResolver\FaviconResolver;
use App\Service\Fetch\FaviconResolver\FaviconResolverInterface;
use App\Service\Image\Exception\GuardedImageUnavailableException;
use App\Service\Image\GuardedImageDownloader\GuardedImageDownloader;
use App\Service\Image\GuardedImageDownloader\GuardedImageDownloaderInterface;
use App\Service\Image\Model\DownloadedImageModel;
use App\Tests\DbTestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

final class WarmCatalogFaviconsCommandTest extends DbTestCase
{
    private function persistFeed(string $title, string $url): CatalogFeed
    {
        $category = new CatalogCategory('technology' . $title, 'Technology', 'memory', '#3b82f6');
        $feed = new CatalogFeed($category, $title, $url);
        $feed->setSiteUrl('https://example.com');
        $this->entityManager->persist($category);
        $this->entityManager->persist($feed);
        $this->entityManager->flush();

        return $feed;
    }

    private function tester(GuardedImageDownloaderInterface $downloader): CommandTester
    {
        // The warmer's interfaces auto-alias to the concrete ids, and the test container replaces those.
        self::getContainer()->set(GuardedImageDownloader::class, $downloader);

        // Stub resolution too, so the warmer's up-front resolveAll() never
        // touches the network: hand every site the same canned icon URL, which
        // the mocked downloader above then "downloads".
        $resolver = $this->createStub(FaviconResolverInterface::class);
        $resolver->method('resolveAll')->willReturnCallback(
            static fn (array $bases): array => array_map(
                static fn (): string => 'https://example.com/favicon.ico',
                $bases,
            ),
        );
        self::getContainer()->set(FaviconResolver::class, $resolver);

        $application = new Application(self::$kernel ?? self::bootKernel());

        return new CommandTester($application->find('app:catalog:warm-favicons'));
    }

    public function testFillsAMissingIconAndIsANoOpOnASecondRun(): void
    {
        $feed = $this->persistFeed('The Verge', 'https://www.theverge.com/rss/index.xml');

        $downloader = $this->createMock(GuardedImageDownloaderInterface::class);
        $downloader->expects($this->once())
            ->method('download')
            ->willReturn(new DownloadedImageModel('https://example.com/favicon.ico', 'PNGBYTES', 'image/png'));

        $tester = $this->tester($downloader);

        $tester->execute([]);
        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('warmed 1', $tester->getDisplay());

        $this->entityManager->clear();
        $reloaded = $this->entityManager->find(CatalogFeed::class, $feed->getId());
        self::assertNotNull($reloaded);
        self::assertSame('PNGBYTES', $reloaded->getFaviconBytes());

        // Second run: the row is fresh, so the downloader must not be called again —
        // guaranteed by expects(once()) above.
        $tester->execute([]);
        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('warmed 0', $tester->getDisplay());
    }

    public function testRecordsAFailureAndStillExitsZero(): void
    {
        $this->persistFeed('Dead Feed', 'https://dead.example.com/rss.xml');

        $downloader = $this->createStub(GuardedImageDownloaderInterface::class);
        $downloader->method('download')->willThrowException(new GuardedImageUnavailableException('gone'));

        $tester = $this->tester($downloader);
        $tester->execute([]);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('failed 1', $tester->getDisplay());

        $this->entityManager->clear();
        $rows = $this->entityManager->getRepository(CatalogFeed::class)->findAll();
        self::assertNotNull($rows[0]->getFaviconFailedAt());
    }

    public function testLimitBoundsTheRun(): void
    {
        $this->persistFeed('One', 'https://one.example.com/rss.xml');
        $this->persistFeed('Two', 'https://two.example.com/rss.xml');

        $downloader = $this->createMock(GuardedImageDownloaderInterface::class);
        $downloader->expects($this->once())
            ->method('download')
            ->willReturn(new DownloadedImageModel('https://example.com/favicon.ico', 'PNGBYTES', 'image/png'));

        $tester = $this->tester($downloader);
        $tester->execute(['--limit' => '1']);

        self::assertSame(0, $tester->getStatusCode());
    }

    public function testForceReWarmsAlreadyFreshRowsAndTerminates(): void
    {
        $this->persistFeed('One', 'https://one.example.com/rss.xml');
        $this->persistFeed('Two', 'https://two.example.com/rss.xml');

        // Both runs download two icons: the plain run warms the two fresh rows,
        // then --force re-warms the same two. atLeast(4) proves force actually
        // re-downloaded rather than skipping the already-fresh rows.
        $downloader = $this->createMock(GuardedImageDownloaderInterface::class);
        $downloader->expects($this->atLeast(4))
            ->method('download')
            ->willReturn(new DownloadedImageModel('https://example.com/favicon.ico', 'PNGBYTES', 'image/png'));

        $tester = $this->tester($downloader);

        $tester->execute([]);
        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('warmed 2', $tester->getDisplay());

        // The key property: --force with no --limit COMPLETES (a hang here means
        // the loop never converges) and re-warms the just-warmed rows.
        $tester->execute(['--force' => true]);
        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('warmed 2', $tester->getDisplay());
    }
}
