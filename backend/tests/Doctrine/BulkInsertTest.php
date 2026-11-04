<?php

declare(strict_types=1);

namespace App\Tests\Doctrine;

use App\Doctrine\BulkInsert;
use App\Tests\DbTestCase;

final class BulkInsertTest extends DbTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $connection = $this->entityManager->getConnection();
        $connection->executeStatement(
            'CREATE TEMPORARY TABLE IF NOT EXISTS bulk_insert_probe (position INT, label VARCHAR(10))',
        );
        $connection->executeStatement('DELETE FROM bulk_insert_probe');
    }

    public function testRowsSpreadOverSeveralStatementsAreAllWrittenAndCounted(): void
    {
        $connection = $this->entityManager->getConnection();

        $written = BulkInsert::into('INSERT INTO bulk_insert_probe (position, label)', 2)
            ->rows($connection, [[1, 'one'], [2, 'two'], [3, 'three']]);

        self::assertSame(3, $written);
        self::assertSame(
            ['one', 'two', 'three'],
            $connection->fetchFirstColumn('SELECT label FROM bulk_insert_probe ORDER BY position'),
        );
    }
}
