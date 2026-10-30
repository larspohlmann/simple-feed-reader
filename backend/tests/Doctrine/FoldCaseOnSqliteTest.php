<?php

declare(strict_types=1);

namespace App\Tests\Doctrine;

use App\Doctrine\CaseFolding;
use App\Tests\DbTestCase;

/** Read from the application's connection, so it proves SqliteConnectionSetupDriver registered the function. */
final class FoldCaseOnSqliteTest extends DbTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->skipUnlessSqlite();
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
