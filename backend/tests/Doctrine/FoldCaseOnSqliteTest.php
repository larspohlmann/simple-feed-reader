<?php

declare(strict_types=1);

namespace App\Tests\Doctrine;

use App\Doctrine\CaseFolding;
use App\Tests\DbTestCase;
use Doctrine\DBAL\Platforms\SQLitePlatform;

/** Read from the application's connection, so it proves SqliteConnectionSetupDriver registered the function. */
final class FoldCaseOnSqliteTest extends DbTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (!$this->entityManager->getConnection()->getDatabasePlatform() instanceof SQLitePlatform) {
            self::markTestSkipped('The native function is SQLite-only; this leg runs on another platform.');
        }
    }

    public function testTheConnectionFoldsLikePhpDoes(): void
    {
        $text = 'ÄRGER im Büro, ΑΘΗΝΑ';

        self::assertSame(
            CaseFolding::fold($text),
            $this->entityManager->getConnection()->fetchOne('SELECT FOLD_CASE(?)', [$text]),
        );
    }

    public function testANullHaystackStaysNull(): void
    {
        self::assertNull($this->entityManager->getConnection()->fetchOne('SELECT FOLD_CASE(NULL)'));
    }
}
