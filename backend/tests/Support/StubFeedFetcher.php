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
     * The answer for every unstubbed URL. Opt-in: the default LogicException keeps a test honest about its requests,
     * but a subject that guesses addresses (feed-path probing) can only say "nothing else is out there".
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
        // Before the yield: a caller holding the first outcome has paid the whole wave, as with the real engine.
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
