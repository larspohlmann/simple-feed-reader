<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\Entry;
use App\Repository\SearchTermsPredicateBuilder;
use App\Service\Search\Model\SearchTermsModel;
use App\Tests\DbTestCase;

final class SearchTermsPredicateBuilderTest extends DbTestCase
{
    public function testAnAsciiTermKeepsOneNativeLikePerColumn(): void
    {
        $predicate = $this->predicateFor('angular');

        self::assertSame(3, substr_count($predicate, ' LIKE '));
        self::assertStringNotContainsString('FOLD_CASE', $predicate);
    }

    public function testANonAsciiTermFoldsEachColumnBehindANativeAnchor(): void
    {
        $predicate = $this->predicateFor('ärger');

        self::assertSame(6, substr_count($predicate, ' LIKE '));
        self::assertSame(3, substr_count($predicate, 'FOLD_CASE('));
    }

    private function predicateFor(string $input): string
    {
        $qb = $this->entityManager->createQueryBuilder()->select('e.id')->from(Entry::class, 'e');

        return (new SearchTermsPredicateBuilder())->build($qb, SearchTermsModel::fromInput($input), 'term');
    }
}
