<?php

declare(strict_types=1);

// Fixtures for QueriesLiveInRepositoriesRuleTest, excluded from `composer stan` like everything in this directory.
/** @noinspection PhpIllegalPsrClassPathInspection */

namespace App\Service\Fixtures {
    use Doctrine\DBAL\Connection;
    use Doctrine\ORM\EntityManagerInterface;
    use Doctrine\ORM\QueryBuilder;

    final readonly class BuildsDql
    {
        public function __construct(private EntityManagerInterface $em)
        {
        }

        public function run(): mixed
        {
            return $this->em->createQuery('SELECT 1')->execute();
        }

        public function builder(): QueryBuilder
        {
            return $this->em->createQueryBuilder();
        }
    }

    final readonly class HoldsTheConnection
    {
        public function __construct(private Connection $connection)
        {
        }

        public function purge(): void
        {
            $this->connection->executeStatement('DELETE FROM messenger_messages');
        }
    }

    final readonly class OwnsTheUnitOfWork
    {
        public function __construct(private EntityManagerInterface $em)
        {
        }

        public function save(object $entity): void
        {
            $this->em->wrapInTransaction(function () use ($entity): void {
                $this->em->persist($entity);
            });
        }
    }

    final readonly class LegacyQuery
    {
        public function __construct(private EntityManagerInterface $em)
        {
        }

        public function run(): mixed
        {
            return $this->em->createQuery('SELECT 1')->execute();
        }
    }
}

namespace App\Controller\Fixtures {
    use Doctrine\ORM\EntityManagerInterface;

    final readonly class ReachesForTheConnection
    {
        public function __construct(private EntityManagerInterface $em)
        {
        }

        public function ping(): void
        {
            $this->em?->getConnection()->executeQuery('SELECT 1');
        }
    }
}

namespace App\Repository\Fixtures {
    use Doctrine\DBAL\Connection;
    use Doctrine\ORM\EntityManagerInterface;

    final readonly class QueriesHere
    {
        public function __construct(private EntityManagerInterface $em, private Connection $connection)
        {
        }

        public function run(): mixed
        {
            $this->connection->executeStatement('DELETE FROM messenger_messages');

            return $this->em->createQueryBuilder()->getQuery()->execute();
        }
    }
}

namespace App\Doctrine\Fixtures {
    use Doctrine\DBAL\Connection;
    use Doctrine\DBAL\Platforms\SQLitePlatform;

    final readonly class ExtendsTheOrm
    {
        public function __construct(private Connection $connection)
        {
        }

        public function isSqlite(): bool
        {
            return $this->connection->getDatabasePlatform() instanceof SQLitePlatform;
        }
    }
}

namespace App\Tests\Fixtures {
    use Doctrine\ORM\EntityManagerInterface;

    final readonly class AssertsOnTheDatabase
    {
        public function __construct(private EntityManagerInterface $em)
        {
        }

        public function count(): mixed
        {
            return $this->em->createQuery('SELECT COUNT(f.id) FROM App\Entity\Feed f')->getSingleScalarResult();
        }
    }
}
