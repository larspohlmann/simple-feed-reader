<?php

declare(strict_types=1);

namespace App\Tests\Service\Search\Index;

use App\Service\Search\Index\IndexSearch;
use App\Service\Search\SearchTerms;
use PHPUnit\Framework\TestCase;

final class IndexSearchTest extends TestCase
{
    public function testASearchOverNoFeedsIsRefusedRatherThanWidenedToEveryFeed(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new IndexSearch(SearchTerms::fromInput('widgets'), [], null, 20);
    }

    public function testAMembershipProbeSpansEveryFeedAndLimitsToItsCandidateCount(): void
    {
        $probe = IndexSearch::amongEntries(SearchTerms::fromInput('widgets'), [5, 9, 12]);

        self::assertNull($probe->feedIds);
        self::assertSame([5, 9, 12], $probe->entryIds);
        self::assertSame(3, $probe->limit);
        self::assertNull($probe->cursor);
    }
}
