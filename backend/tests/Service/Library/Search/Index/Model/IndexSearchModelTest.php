<?php

declare(strict_types=1);

namespace App\Tests\Service\Search\Index\Model;

use App\Service\Search\Index\Model\IndexSearchModel;
use App\Service\Search\Model\SearchTermsModel;
use PHPUnit\Framework\TestCase;

final class IndexSearchModelTest extends TestCase
{
    public function testASearchOverNoFeedsIsRefusedRatherThanWidenedToEveryFeed(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new IndexSearchModel(SearchTermsModel::fromInput('widgets'), [], null, 20);
    }

    public function testAMembershipProbeSpansEveryFeedAndLimitsToItsCandidateCount(): void
    {
        $probe = IndexSearchModel::amongEntries(SearchTermsModel::fromInput('widgets'), [5, 9, 12]);

        self::assertNull($probe->feedIds);
        self::assertSame([5, 9, 12], $probe->entryIds);
        self::assertSame(3, $probe->limit);
        self::assertNull($probe->cursor);
    }
}
