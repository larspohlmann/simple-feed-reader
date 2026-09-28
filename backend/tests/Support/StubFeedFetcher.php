<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Service\Fetch\BatchFeedFetcher\BatchFeedFetcherInterface;
use App\Service\Fetch\Exception\FetchException;
use App\Service\Fetch\FeedFetcher\FeedFetcherInterface;
use App\Service\Fetch\Model\FetchOutcomeModel;
use App\Service\Fetch\Model\FetchResponseModel;
use App\Service\Fetch\Model\FetchTicketModel;
use Symfony\Component\Clock\MockClock;

final class StubFeedFetcher implements FeedFetcherInterface, BatchFeedFetcherInterface
{
    /** @var array<string, FetchResponseModel|FetchException> */
    private array $results = [];

    private ?FetchException $fallbackResult = null;

    /** @var list<string> */
    public array $fetchedUrls = [];

    /**
     * Wall-clock cost of one wave of concurrent fetches, not of one fetch. A
     * batch of `concurrency` feeds advances the clock once.
     */
    public int $secondsPerFetch = 0;

    public function __construct(
        private readonly ?MockClock $clock = null,
        private readonly int $concurrency = 8,
    ) {
    }

    public function willReturn(string $url, FetchResponseModel $response): void
    {
        $this->results[$url] = $response;
    }

    public function willThrow(string $url, FetchException $exception): void
    {
        $this->results[$url] = $exception;
    }

    /**
     * What every URL nobody stubbed answers with. Opt-in, because the default
     * default — a LogicException — is what keeps a test honest about which
     * requests it expects. A test whose subject GUESSES addresses (feed-path
     * probing) cannot list them without re-deriving the code under test, so it
     * says "nothing else is out there" once instead.
     */
    public function willThrowForEverythingElse(FetchException $exception): void
    {
        $this->fallbackResult = $exception;
    }

    public function fetch(string $url): FetchResponseModel
    {
        foreach ($this->fetchAll([new FetchTicketModel($url)]) as $outcome) {
            return $outcome->responseOrThrow();
        }

        throw new \LogicException('No outcome for ' . $url);
    }

    /** @return \Generator<int|string, FetchOutcomeModel> */
    public function fetchAll(iterable $tickets): \Generator
    {
        $wave = [];

        foreach ($tickets as $key => $ticket) {
            $wave[$key] = $ticket;
            if (\count($wave) < $this->concurrency) {
                continue;
            }

            yield from $this->runWave($wave);
            $wave = [];
        }

        if ([] !== $wave) {
            yield from $this->runWave($wave);
        }
    }

    /**
     * @param array<int|string, FetchTicketModel> $wave
     *
     * @return \Generator<int|string, FetchOutcomeModel>
     */
    private function runWave(array $wave): \Generator
    {
        // Must precede the yielding loop below: a caller consuming the first
        // outcome should already have paid the whole wave's cost, matching the
        // real engine where nothing is readable until the network has answered.
        // Task 9's budget assertions rely on this ordering.
        if ($this->secondsPerFetch > 0) {
            $this->clock?->sleep($this->secondsPerFetch);
        }

        foreach ($wave as $key => $ticket) {
            $this->fetchedUrls[] = $ticket->url;

            $result = $this->results[$ticket->url]
                ?? $this->fallbackResult
                ?? throw new \LogicException('No stubbed result for ' . $ticket->url);

            yield $key => $result instanceof FetchException
                ? FetchOutcomeModel::failed($result)
                : FetchOutcomeModel::succeeded($result);
        }
    }
}
