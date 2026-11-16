<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\Social\Support;

use App\Enum\SocialNetwork;
use App\Service\Social\Social\Exception\SearchQueryTooBroadException;
use App\Service\Social\Social\Support\NetworkSearchQueries;
use PHPUnit\Framework\TestCase;

final class NetworkSearchQueriesTest extends TestCase
{
    private const string TOO_BROAD = '(a || b || c) (d || e || f)';

    public function testAQueryThatSpellsOutWithinTheCapFits(): void
    {
        self::assertTrue(NetworkSearchQueries::fits(SocialNetwork::Mastodon, 'rss'));
    }

    public function testAQueryThatSpellsOutBeyondTheCapDoesNotFit(): void
    {
        self::assertFalse(NetworkSearchQueries::fits(SocialNetwork::Mastodon, self::TOO_BROAD));
    }

    public function testRequireFitsPassesWithinTheCap(): void
    {
        $this->expectNotToPerformAssertions();

        NetworkSearchQueries::requireFits(SocialNetwork::Mastodon, 'rss');
    }

    public function testRequireFitsThrowsBeyondTheCap(): void
    {
        $this->expectException(SearchQueryTooBroadException::class);

        NetworkSearchQueries::requireFits(SocialNetwork::Mastodon, self::TOO_BROAD);
    }

    public function testBlueskyRunsTheQueryAsWritten(): void
    {
        self::assertSame(['a || b'], NetworkSearchQueries::of(SocialNetwork::Bluesky, 'a || b'));
    }

    public function testMastodonRunsOneSearchPerAlternative(): void
    {
        self::assertSame(['a', 'b'], NetworkSearchQueries::of(SocialNetwork::Mastodon, 'a || b'));
    }

    public function testLemmyAsksOneTermPerAlternative(): void
    {
        self::assertSame(
            ['rss reader', 'atom'],
            NetworkSearchQueries::of(SocialNetwork::Lemmy, '"rss reader" || atom'),
        );
    }

    public function testALemmyQueryWithoutATermDoesNotFit(): void
    {
        self::assertFalse(NetworkSearchQueries::fits(SocialNetwork::Lemmy, '-gazette'));
    }

    public function testNineLemmyAlternativesDoNotFit(): void
    {
        $nineAlternatives = 'a || b || c || d || e || f || g || h || i';

        self::assertFalse(NetworkSearchQueries::fits(SocialNetwork::Lemmy, $nineAlternatives));
    }
}
