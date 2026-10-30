<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\Entry;
use App\Repository\IndexSearchPredicates;
use App\Repository\LikeSearchPredicates;
use App\Repository\SearchTermsPredicateBuilder;
use App\Service\Search\Model\SearchTermsModel;
use App\Tests\DbTestCase;
use Doctrine\DBAL\Platforms\SQLitePlatform;

final class SearchTermsPredicateBuilderTest extends DbTestCase
{
    public function testSqliteAsksTheIndexAloneForTermsItCanFind(): void
    {
        $this->skipUnlessSqlite();
        $predicate = $this->predicateFor('angular signals');

        self::assertSame(1, substr_count($predicate, 'SEARCH_INDEX_MATCHES('));
        self::assertStringNotContainsString(' LIKE ', $predicate);
    }

    public function testSqliteNarrowsAWholeWordCheckToTheIndexCandidates(): void
    {
        $this->skipUnlessSqlite();
        $predicate = $this->predicateFor('E-Mail ');

        self::assertSame(1, substr_count($predicate, 'SEARCH_INDEX_MATCHES('));
        self::assertSame(3, substr_count($predicate, 'NORMALIZE_WORD_BOUNDARIES('));
    }

    public function testSqliteFoldsTermsTooShortForTheIndex(): void
    {
        $this->skipUnlessSqlite();
        $predicate = $this->predicateFor('öl tv');

        self::assertStringNotContainsString('SEARCH_INDEX_MATCHES', $predicate);
        self::assertSame(6, substr_count($predicate, 'SEARCH_FOLD('));
    }

    public function testMysqlKeepsOneLikePerColumn(): void
    {
        if ($this->entityManager->getConnection()->getDatabasePlatform() instanceof SQLitePlatform) {
            self::markTestSkipped('SQLite asks its search index; the tests above cover it.');
        }
        $predicate = $this->predicateFor('öl tv');

        self::assertSame(6, substr_count($predicate, ' LIKE '));
        self::assertStringNotContainsString('SEARCH_', $predicate);
    }

    private function predicateFor(string $input): string
    {
        $qb = $this->entityManager->createQueryBuilder()->select('e.id')->from(Entry::class, 'e');
        $builder = new SearchTermsPredicateBuilder(new LikeSearchPredicates(), new IndexSearchPredicates());

        return $builder->build($qb, SearchTermsModel::fromInput($input), 'term');
    }
}
