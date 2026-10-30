<?php

declare(strict_types=1);

namespace App\Tests\Doctrine;

use App\Doctrine\SearchFolding;
use App\Doctrine\WordBoundaries;
use App\Tests\DbTestCase;

/**
 * The function is read from the connection the APPLICATION uses, so the test
 * proves SqliteConnectionSetupDriver registered it, not that PHP can normalize.
 */
final class NormalizeWordBoundariesOnSqliteTest extends DbTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->skipUnlessSqlite();
    }

    public function testTheConnectionNormalizesAndFoldsLikePhpDoes(): void
    {
        $text = 'E-Mail (heute), „Corona-Krise“/US-Wahl! ÄRGER im Café';

        self::assertSame(
            SearchFolding::fold(WordBoundaries::normalize($text)),
            $this->entityManager->getConnection()->fetchOne('SELECT NORMALIZE_WORD_BOUNDARIES(?)', [$text]),
        );
    }

    public function testANullHaystackStaysNull(): void
    {
        self::assertNull($this->entityManager->getConnection()->fetchOne('SELECT NORMALIZE_WORD_BOUNDARIES(NULL)'));
    }
}
