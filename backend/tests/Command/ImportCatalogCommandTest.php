<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Entity\CatalogFeed;
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

        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        self::assertCount(111, $em->getRepository(CatalogFeed::class)->findAll());
    }

    public function testAMissingFileIsAnError(): void
    {
        $tester = $this->tester();
        $tester->execute(['--file' => '/nonexistent/catalog.opml']);

        self::assertSame(1, $tester->getStatusCode());
    }

    public function testAnUnknownModeIsAnErrorAndImportsNothing(): void
    {
        $tester = $this->tester();
        $tester->execute(['--mode' => 'nonsense']);

        self::assertSame(1, $tester->getStatusCode());

        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        self::assertCount(0, $em->getRepository(CatalogFeed::class)->findAll());
    }
}
