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

    public function fetchAll(array $socialFeedsByFeedId, iterable $feeds): \Generator
    {
        $this->socialFeedsByFeedId = $socialFeedsByFeedId;
        foreach ($feeds as $feedId => $feed) {
            $this->asked[] = $feedId;

            yield $feedId => $this->outcomes[$feed->getUrl()]
                ?? Assert::fail('No timeline outcome scripted for ' . $feed->getUrl());
        }
    }
}
