<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Entity\CatalogFeed;
use App\Service\Catalog\BundledCatalog;
use App\Tests\DbTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

final class ImportCatalogCommandTest extends DbTestCase
{
    private function tester(): CommandTester
    {
        $application = new Application(self::$kernel ?? self::bootKernel());

        return new CommandTester($application->find('app:catalog:import'));
    }

    public function testImportsTheShippedDocumentByDefault(): void
    {
        $tester = $this->tester();
        $tester->execute([]);

        self::assertSame(0, $tester->getStatusCode());

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $catalog = self::getContainer()->get(BundledCatalog::class);
        self::assertInstanceOf(BundledCatalog::class, $catalog);

        $shippedFeedCount = $catalog->document()->feedCount();
        self::assertGreaterThan(0, $shippedFeedCount);
        self::assertCount($shippedFeedCount, $entityManager->getRepository(CatalogFeed::class)->findAll());
    }

    /**
     * The production start script runs the import on every start, so it passes
     * --if-empty. On a fresh install that is an ordinary import.
     */
    public function testIfEmptySeedsACatalogThatHasNothingInIt(): void
    {
        $tester = $this->tester();
        $tester->execute(['--if-empty' => true]);

        self::assertSame(0, $tester->getStatusCode());

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        self::assertNotEmpty($entityManager->getRepository(CatalogFeed::class)->findAll());
    }

    /**
     * And on every start after that it must keep its hands off: the catalog in
     * the database is the admin's, including the rows they deleted from it.
     */
    public function testIfEmptyLeavesAnExistingCatalogUntouched(): void
    {
        $this->tester()->execute([]);

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $feeds = $entityManager->getRepository(CatalogFeed::class);
        $deleted = $feeds->findAll()[0];
        $deletedUrl = $deleted->getUrl();
        $entityManager->remove($deleted);
        $entityManager->flush();
        $entityManager->clear();

        $tester = $this->tester();
        $tester->execute(['--if-empty' => true]);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('imported nothing', $tester->getDisplay());
        self::assertNull($feeds->findOneBy(['url' => $deletedUrl]));
    }

    public function testAMissingFileIsAnError(): void
    {
        $tester = $this->tester();
        $tester->execute(['--file' => '/nonexistent/catalog.opml']);

        self::assertSame(1, $tester->getStatusCode());
    }

    public function testAWhitespaceOnlyFileFallsBackToTheShippedDocument(): void
    {
        $tester = $this->tester();
        $tester->execute(['--file' => '   ']);

        self::assertSame(0, $tester->getStatusCode());

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $catalog = self::getContainer()->get(BundledCatalog::class);
        self::assertInstanceOf(BundledCatalog::class, $catalog);

        self::assertCount(
            $catalog->document()->feedCount(),
            $entityManager->getRepository(CatalogFeed::class)->findAll(),
        );
    }

    public function testAnUnknownModeIsAnErrorAndImportsNothing(): void
    {
        $tester = $this->tester();
        $tester->execute(['--mode' => 'nonsense']);

        self::assertSame(1, $tester->getStatusCode());

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        self::assertCount(0, $entityManager->getRepository(CatalogFeed::class)->findAll());
    }
}
