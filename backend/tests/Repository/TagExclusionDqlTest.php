<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Enum\InclusionSurface;
use App\Repository\TagExclusionDql;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TagExclusionDqlTest extends TestCase
{
    /** @return iterable<string, array{InclusionSurface, string}> */
    public static function surfaces(): iterable
    {
        yield 'All items' => [InclusionSurface::AllItems, 'includeInAllItems'];
        yield 'For You' => [InclusionSurface::ForYou, 'includeInForYou'];
        yield 'saved searches' => [InclusionSurface::SavedSearches, 'includeInSavedSearches'];
    }

    #[DataProvider('surfaces')]
    public function testItAsksTheTagAndEachAncestorForTheSurfacesSwitch(InclusionSurface $surface, string $switch): void
    {
        $predicate = TagExclusionDql::notExcluded('s', $surface);

        self::assertStringContainsString(
            \sprintf(
                '(sExcludingTag0.%1$s = false OR sExcludingTag1.%1$s = false OR sExcludingTag2.%1$s = false)',
                $switch,
            ),
            $predicate,
        );
    }

    public function testItNamesItsAliasesAfterTheSubscriptionSoTwoCallersDoNotClash(): void
    {
        self::assertSame(
            'NOT EXISTS (SELECT 1 FROM App\\Entity\\SubscriptionTag collapsedExclusionJoin'
            . ' JOIN collapsedExclusionJoin.tag collapsedExcludingTag0'
            . ' LEFT JOIN collapsedExcludingTag0.parent collapsedExcludingTag1'
            . ' LEFT JOIN collapsedExcludingTag1.parent collapsedExcludingTag2'
            . ' WHERE collapsedExclusionJoin.subscription = collapsed'
            . ' AND (collapsedExcludingTag0.includeInAllItems = false'
            . ' OR collapsedExcludingTag1.includeInAllItems = false'
            . ' OR collapsedExcludingTag2.includeInAllItems = false))',
            TagExclusionDql::notExcluded('collapsed', InclusionSurface::AllItems),
        );
    }
}
