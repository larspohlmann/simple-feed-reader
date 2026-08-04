<?php

declare(strict_types=1);

namespace App\Tests\Doctrine;

use App\Tests\DbTestCase;
use Doctrine\DBAL\Platforms\SQLitePlatform;

/**
 * The pragmas are read back from the connection the APPLICATION uses, not from
 * one this test builds: the middleware only matters if doctrine.yaml actually
 * wires it, and a driver-level unit test would pass either way.
 */
final class SqlitePragmaTest extends DbTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (!$this->em->getConnection()->getDatabasePlatform() instanceof SQLitePlatform) {
            self::markTestSkipped('The pragmas apply to SQLite; this leg runs on another platform.');
        }
    }

    public function testForeignKeysAreEnforced(): void
    {
        self::assertSame('1', $this->pragma('foreign_keys'));
    }

    /**
     * Without this the whole database locks for the length of every write, and
     * a refresh sweep writes for as long as it takes to ingest every feed.
     */
    public function testTheDatabaseUsesWriteAheadLogging(): void
    {
        self::assertSame('wal', strtolower($this->pragma('journal_mode')));
    }

    private function pragma(string $name): string
    {
        // The pragma FUNCTION, because a bare `PRAGMA x` returns no result set
        // through the query path.
        $value = $this->em->getConnection()->fetchOne(sprintf('SELECT * FROM pragma_%s()', $name));
        self::assertIsScalar($value);

        return (string) $value;
    }
}
