<?php

declare(strict_types=1);

namespace App\Tests\Support;

use PHPUnit\Framework\TestCase;

// No #[CoversClass]: phpunit.dist.xml scopes <source> to src/, so a test-support
// class is not a valid coverage target and the attribute warns under coverage.
final class WorkerDatabaseUrlTest extends TestCase
{
    public function testItGivesEachWorkerItsOwnSqliteFile(): void
    {
        self::assertSame(
            'sqlite:///%kernel.project_dir%/var/data_test3.db',
            WorkerDatabaseUrl::forWorker('sqlite:///%kernel.project_dir%/var/data_test.db', '3'),
        );
    }

    public function testTwoWorkersNeverShareAFile(): void
    {
        $databaseUrl = 'sqlite:///var/data_test.db';

        self::assertNotSame(
            WorkerDatabaseUrl::forWorker($databaseUrl, '0'),
            WorkerDatabaseUrl::forWorker($databaseUrl, '1'),
        );
    }

    public function testASerialRunKeepsTheUnsuffixedFile(): void
    {
        self::assertSame(
            'sqlite:///var/data_test.db',
            WorkerDatabaseUrl::forWorker('sqlite:///var/data_test.db', ''),
        );
    }

    public function testItLeavesMysqlAlone(): void
    {
        $databaseUrl = 'mysql://root:root@127.0.0.1:3306/feedreader_test?serverVersion=8.4';

        self::assertSame($databaseUrl, WorkerDatabaseUrl::forWorker($databaseUrl, '3'));
    }

    public function testItLeavesAnInMemoryDatabaseAlone(): void
    {
        self::assertSame(
            'sqlite:///:memory:',
            WorkerDatabaseUrl::forWorker('sqlite:///:memory:', '3'),
        );
    }

    public function testItSuffixesTheFileNameRatherThanTheDirectory(): void
    {
        self::assertSame(
            'sqlite:///var/my.data/data_test7.db',
            WorkerDatabaseUrl::forWorker('sqlite:///var/my.data/data_test.db', '7'),
        );
    }
}
