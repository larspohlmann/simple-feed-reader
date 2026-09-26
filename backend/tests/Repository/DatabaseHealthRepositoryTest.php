<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Repository\DatabaseHealthRepository;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;

final class DatabaseHealthRepositoryTest extends TestCase
{
    public function testPingLetsAnUnreachableDatabaseThrow(): void
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('executeQuery')->willThrowException(new \RuntimeException('connection refused'));

        $this->expectException(\RuntimeException::class);

        (new DatabaseHealthRepository($connection))->ping();
    }
}
