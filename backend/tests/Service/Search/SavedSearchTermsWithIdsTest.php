<?php

declare(strict_types=1);

namespace App\Tests\Service\Search;

use App\Service\Search\Exception\SavedSearchTermsIdMismatchException;
use App\Service\Search\SavedSearchTermsWithIds;
use App\Service\Search\SearchMode;
use App\Service\Search\SearchTerms;
use PHPUnit\Framework\TestCase;

final class SavedSearchTermsWithIdsTest extends TestCase
{
    public function testPairsTermsAndIdsOfEqualLength(): void
    {
        $terms = SearchTerms::fromTermAndMode('climate', SearchMode::Substring);

        $pair = new SavedSearchTermsWithIds([$terms], [10]);

        self::assertSame([$terms], $pair->terms);
        self::assertSame([10], $pair->ids);
    }

    public function testRejectsMoreTermsThanIds(): void
    {
        $terms = SearchTerms::fromTermAndMode('climate', SearchMode::Substring);

        $this->expectException(SavedSearchTermsIdMismatchException::class);

        new SavedSearchTermsWithIds([$terms], []);
    }

    public function testRejectsMoreIdsThanTerms(): void
    {
        $this->expectException(SavedSearchTermsIdMismatchException::class);

        new SavedSearchTermsWithIds([], [10]);
    }
}
