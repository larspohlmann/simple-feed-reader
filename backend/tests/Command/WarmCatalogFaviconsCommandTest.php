<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Entity\CatalogCategory;
use App\Entity\CatalogFeed;
use App\Service\Catalog\CatalogFaviconFetcher;
use App\Service\Catalog\CatalogFaviconFetcherInterface;
use App\Service\Catalog\Exception\FaviconUnavailableException;
use App\Service\Catalog\FetchedFavicon;
use App\Service\Fetch\FaviconResolver;
use App\Service\Fetch\FaviconResolverInterface;
use App\Tests\DbTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

final class WarmCatalogFaviconsCommandTest extends DbTestCase
{
    private function em(): EntityManagerInterface
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        return $em;
    }

    private function persistFeed(string $title, string $url): CatalogFeed
    {
        $category = new CatalogCategory('technology' . $title, 'Technology', 'memory', '#3b82f6');
        $feed = new CatalogFeed($category, $title, $url);
        $feed->setSiteUrl('https://example.com');
        $this->em()->persist($category);
        $this->em()->persist($feed);
        $this->em()->flush();

        return $feed;
    }

    private function tester(CatalogFaviconFetcherInterface $fetcher): CommandTester
    {
        // The warmer autowires the interfaces, which Symfony auto-aliases to the
        // single concrete implementation. The test container honours set() on the
        // concrete service ids, so override those — an interface mock satisfies
        // the constructor's interface type.
        self::getContainer()->set(CatalogFaviconFetcher::class, $fetcher);

        // Stub resolution too, so the warmer's up-front resolveAll() never
        // touches the network: hand every site the same canned icon URL, which
        // the mocked fetcher above then "downloads".
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

        $fetcher = $this->createMock(CatalogFaviconFetcherInterface::class);
        $fetcher->expects(self::once())
            ->method('download')
            ->willReturn(new FetchedFavicon('https://example.com/favicon.ico', 'PNGBYTES', 'image/png'));

        $tester = $this->tester($fetcher);

        $tester->execute([]);
        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('warmed 1', $tester->getDisplay());

        $this->em()->clear();
        $reloaded = $this->em()->find(CatalogFeed::class, $feed->getId());
        self::assertNotNull($reloaded);
        self::assertSame('PNGBYTES', $reloaded->getFaviconBytes());

        // Second run: the row is fresh, so the fetcher must not be called again —
        // guaranteed by expects(once()) above.
        $tester->execute([]);
        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('warmed 0', $tester->getDisplay());
    }

    public function testRecordsAFailureAndStillExitsZero(): void
    {
        $this->persistFeed('Dead Feed', 'https://dead.example.com/rss.xml');

        $fetcher = $this->createStub(CatalogFaviconFetcherInterface::class);
        $fetcher->method('download')->willThrowException(new FaviconUnavailableException('gone'));

        $tester = $this->tester($fetcher);
        $tester->execute([]);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('failed 1', $tester->getDisplay());

        $this->em()->clear();
        $rows = $this->em()->getRepository(CatalogFeed::class)->findAll();
        self::assertNotNull($rows[0]->getFaviconFailedAt());
    }

    public function testLimitBoundsTheRun(): void
    {
        $this->persistFeed('One', 'https://one.example.com/rss.xml');
        $this->persistFeed('Two', 'https://two.example.com/rss.xml');

        $fetcher = $this->createMock(CatalogFaviconFetcherInterface::class);
        $fetcher->expects(self::once())
            ->method('download')
            ->willReturn(new FetchedFavicon('https://example.com/favicon.ico', 'PNGBYTES', 'image/png'));

        $tester = $this->tester($fetcher);
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
        $fetcher = $this->createMock(CatalogFaviconFetcherInterface::class);
        $fetcher->expects(self::atLeast(4))
            ->method('download')
            ->willReturn(new FetchedFavicon('https://example.com/favicon.ico', 'PNGBYTES', 'image/png'));

        $tester = $this->tester($fetcher);

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
