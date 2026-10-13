<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\SocialFeed;
use App\Service\Social\Model\TimelineOutcome\TimelineOutcomeModelInterface;
use App\Service\Social\TimelineFetcher\TimelineFetcherInterface;
use PHPUnit\Framework\Assert;

final class StubTimelineFetcher implements TimelineFetcherInterface
{
    /** @var list<int> */
    public array $asked = [];

    /** @var array<int, SocialFeed> */
    public array $socialFeedsByFeedId = [];

    /** @param array<string, TimelineOutcomeModelInterface> $outcomes by the private feed's URL */
    public function __construct(private readonly array $outcomes = [])
    {
    }

    public function fetchAll(iterable $socialFeeds): \Generator
    {
        foreach ($socialFeeds as $feedId => $socialFeed) {
            $this->asked[] = $feedId;
            $this->socialFeedsByFeedId[$feedId] = $socialFeed;
            $url = $socialFeed->getFeed()->getUrl();

            yield $feedId => $this->outcomes[$url] ?? Assert::fail('No timeline outcome scripted for ' . $url);
        }
    }
}
