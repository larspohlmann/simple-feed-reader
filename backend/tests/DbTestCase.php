<?php

declare(strict_types=1);

namespace App\Tests;

use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

abstract class DbTestCase extends KernelTestCase
{
    protected EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::bootKernel();
        /** @var EntityManagerInterface $entityManager */
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->entityManager = $entityManager;
    }

    protected function registry(): ManagerRegistry
    {
        $registry = self::getContainer()->get('doctrine');
        self::assertInstanceOf(ManagerRegistry::class, $registry);

        return $registry;
    }

    /** @param class-string $entityClass */
    protected function assertNoneManaged(string $entityClass): void
    {
        self::assertSame([], $this->entityManager->getUnitOfWork()->getIdentityMap()[$entityClass] ?? []);
    }

    protected function skipWithoutRowLocks(): void
    {
        if ($this->entityManager->getConnection()->getDatabasePlatform() instanceof SQLitePlatform) {
            self::markTestSkipped('SQLite has no row locks; it serialises writers itself.');
        }
    }

    protected function skipUnlessSqlite(): void
    {
        if (!$this->entityManager->getConnection()->getDatabasePlatform() instanceof SQLitePlatform) {
            self::markTestSkipped('This covers SQLite alone; this leg runs on another platform.');
        }
    }
}
