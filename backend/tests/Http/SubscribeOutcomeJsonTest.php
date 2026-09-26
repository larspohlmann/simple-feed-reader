<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\Http\SubscribeOutcomeJson;
use App\Service\Discovery\FeedCandidate;
use App\Service\Discovery\ScrapeFailureReason;
use App\Service\Subscription\SubscribeOutcome;
use PHPUnit\Framework\TestCase;

final class SubscribeOutcomeJsonTest extends TestCase
{
    public function testListsEveryCandidateWithoutAReasonWhenNoneWasGiven(): void
    {
        $outcome = SubscribeOutcome::candidates([
            new FeedCandidate('https://example.com/rss', 'Example', 'rss'),
            new FeedCandidate('https://example.com/atom', null, 'atom'),
        ]);

        self::assertSame(
            ['candidates' => [
                ['url' => 'https://example.com/rss', 'title' => 'Example', 'format' => 'rss'],
                ['url' => 'https://example.com/atom', 'title' => null, 'format' => 'atom'],
            ]],
            SubscribeOutcomeJson::candidates($outcome),
        );
    }

    public function testCarriesTheScrapeFailureReasonWhenThereIsOne(): void
    {
        $outcome = SubscribeOutcome::candidates([], ScrapeFailureReason::Blocked);

        self::assertSame(
            ['candidates' => [], 'scrapeFailureReason' => 'blocked'],
            SubscribeOutcomeJson::candidates($outcome),
        );
    }
}
