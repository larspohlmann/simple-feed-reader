<?php

declare(strict_types=1);

namespace App\Tests\Doctrine;

use App\Doctrine\SearchIndexQuery;
use PHPUnit\Framework\TestCase;

final class SearchIndexQueryTest extends TestCase
{
    public function testQuotesEveryPieceAndRequiresThemAll(): void
    {
        self::assertSame('"angular" AND "C++"', SearchIndexQuery::allOf(['angular', 'C++']));
    }

    public function testDoublesAQuoteInsideAPiece(): void
    {
        self::assertSame('"say ""hi"""', SearchIndexQuery::allOf(['say "hi"']));
    }

    public function testIndexesAPieceOfThreeCharactersOrMore(): void
    {
        self::assertTrue(SearchIndexQuery::isIndexable('Öl!'));
        self::assertFalse(SearchIndexQuery::isIndexable('Öl'));
    }

    public function testSplitsAWholeWordAtItsBoundariesAndDropsShortPieces(): void
    {
        self::assertSame(['Mail'], SearchIndexQuery::wholeWordPieces('E-Mail'));
        self::assertSame(['Corona', 'Krise'], SearchIndexQuery::wholeWordPieces('Corona-Krise'));
        self::assertSame([], SearchIndexQuery::wholeWordPieces('TV'));
    }
}
