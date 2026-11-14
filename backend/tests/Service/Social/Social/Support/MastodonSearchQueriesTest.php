<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\Social\Support;

use App\Service\Social\Social\Exception\SearchQueryTooBroadException;
use App\Service\Social\Social\Support\MastodonSearchQueries;
use PHPUnit\Framework\TestCase;

final class MastodonSearchQueriesTest extends TestCase
{
    private const string TOO_BROAD = '(a || b || c) (d || e || f)';

    public function testAQueryThatSpellsOutWithinTheCapFits(): void
    {
        self::assertTrue(MastodonSearchQueries::fits('rss'));
    }

    public function testAQueryThatSpellsOutBeyondTheCapDoesNotFit(): void
    {
        self::assertFalse(MastodonSearchQueries::fits(self::TOO_BROAD));
    }

    public function testRequireFitsPassesWithinTheCap(): void
    {
        $this->expectNotToPerformAssertions();

        MastodonSearchQueries::requireFits('rss');
    }

    public function testRequireFitsThrowsBeyondTheCap(): void
    {
        $this->expectException(SearchQueryTooBroadException::class);

        MastodonSearchQueries::requireFits(self::TOO_BROAD);
    }
}
