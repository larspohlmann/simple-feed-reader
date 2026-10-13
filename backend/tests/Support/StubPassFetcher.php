<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\Feed;
use App\Entity\SocialFeed;
use App\Enum\SourceFormat;
use App\Service\Fetch\Exception\FetchException;
use App\Service\Fetch\Model\FetchOutcomeModel;
use App\Service\Fetch\Model\FetchResponseModel;
use App\Service\Fetch\Model\FetchTicketModel;
use App\Service\Refresh\Model\PassOutcome\FeedPassOutcomeModel;
use App\Service\Refresh\Model\PassOutcome\PassOutcomeModelInterface;
use App\Service\Refresh\Model\PassOutcome\TimelinePassOutcomeModel;
use App\Service\Refresh\Pass\RefreshPass;
use App\Service\Refresh\PassFetcher\PassFetcherInterface;
use App\Service\Social\Exception\PrivateFeedOrphanedException;
use App\Service\Social\Model\TimelineOutcome\FailedTimelineModel;
use App\Service\Social\Model\TimelineOutcome\TimelineOutcomeModelInterface;
use PHPUnit\Framework\Assert;
use Symfony\Component\Clock\MockClock;

/** A refresh pass scripted by URL: each RSS feed's answer and each private feed's timeline outcome, in waves. */
final class StubPassFetcher implements PassFetcherInterface
{
    private readonly StubFeedFetcher $feeds;

    /** @var array<string, TimelineOutcomeModelInterface> */
    private array $timelines = [];

    /** @var list<int> */
    public array $asked = [];

    /** @var array<int, SocialFeed> */
    public array $socialFeedsByFeedId = [];

    /** Wall-clock cost of one wave of concurrent fetches, not of one fetch. */
    public int $secondsPerFetch = 0;

    public function __construct(private readonly ?MockClock $clock = null, private readonly int $concurrency = 8)
    {
        $this->feeds = new StubFeedFetcher();
    }

    public function willReturn(string $url, FetchResponseModel $response): void
    {
        $this->feeds->willReturn($url, $response);
    }

    public function willReturnBody(string $url, string $body): void
    {
        $this->feeds->willReturnBody($url, $body);
    }

    public function willThrow(string $url, FetchException $exception): void
    {
        $this->feeds->willThrow($url, $exception);
    }

    public function willReadTimeline(string $url, TimelineOutcomeModelInterface $outcome): void
    {
        $this->timelines[$url] = $outcome;
    }

    /** @return list<string> */
    public function fetchedUrls(): array
    {
        return $this->feeds->fetchedUrls;
    }

    /**
     * @param array<int, SocialFeed> $socialFeedsByFeedId
     *
     * @return \Generator<int, PassOutcomeModelInterface>
     */
    public function fetchAll(RefreshPass $pass, array $socialFeedsByFeedId): \Generator
    {
        $wave = [];
        foreach ($pass->startedFeeds() as $feedId => $feed) {
            $wave[$feedId] = $feed;
            if (\count($wave) < $this->concurrency) {
                continue;
            }

            yield from $this->runWave($socialFeedsByFeedId, $wave);
            $wave = [];
        }

        if ([] !== $wave) {
            yield from $this->runWave($socialFeedsByFeedId, $wave);
        }
    }

    /**
     * @param array<int, SocialFeed> $socialFeedsByFeedId
     * @param array<int, Feed>       $wave
     *
     * @return \Generator<int, PassOutcomeModelInterface>
     */
    private function runWave(array $socialFeedsByFeedId, array $wave): \Generator
    {
        if ($this->secondsPerFetch > 0) {
            $this->clock?->sleep($this->secondsPerFetch);
        }

        foreach ($wave as $feedId => $feed) {
            yield $feedId => SourceFormat::isSocial($feed->getSourceFormat())
                ? new TimelinePassOutcomeModel($this->timelineOf($socialFeedsByFeedId, $feedId))
                : new FeedPassOutcomeModel($this->fetched($feed));
        }
    }

    private function fetched(Feed $feed): FetchOutcomeModel
    {
        return $this->feeds->fetchAll([new FetchTicketModel($feed->getUrl())])->current();
    }

    /** @param array<int, SocialFeed> $socialFeedsByFeedId */
    private function timelineOf(array $socialFeedsByFeedId, int $feedId): TimelineOutcomeModelInterface
    {
        $socialFeed = $socialFeedsByFeedId[$feedId] ?? null;
        if (null === $socialFeed) {
            return new FailedTimelineModel(new PrivateFeedOrphanedException());
        }
        $this->asked[] = $feedId;
        $this->socialFeedsByFeedId[$feedId] = $socialFeed;
        $url = $socialFeed->getFeed()->getUrl();

        return $this->timelines[$url] ?? Assert::fail('No timeline outcome scripted for ' . $url);
    }
}
