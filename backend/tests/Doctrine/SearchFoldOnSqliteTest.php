<?php

declare(strict_types=1);

namespace App\Tests\Doctrine;

use App\Doctrine\SearchFolding;
use App\Tests\DbTestCase;

/** Read from the application's connection, so it proves SqliteConnectionSetupDriver registered the function. */
final class SearchFoldOnSqliteTest extends DbTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->skipUnlessSqlite();
    }

    public function testTheConnectionFoldsLikePhpDoes(): void
    {
        $text = 'ÄRGER im Café, ΑΘΗΝΑ';

        self::assertSame(
            SearchFolding::fold($text),
            $this->entityManager->getConnection()->fetchOne('SELECT SEARCH_FOLD(?)', [$text]),
        );
    }

    public function testANullHaystackStaysNull(): void
    {
        self::assertNull($this->entityManager->getConnection()->fetchOne('SELECT SEARCH_FOLD(NULL)'));
    }
}
