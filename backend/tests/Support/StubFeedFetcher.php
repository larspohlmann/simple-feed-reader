<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Service\Fetch\Exception\FetchException;
use App\Service\Fetch\FeedFetcherInterface;
use App\Service\Fetch\FetchResponse;
use Symfony\Component\Clock\MockClock;

final class StubFeedFetcher implements FeedFetcherInterface
{
    /** @var array<string, FetchResponse|FetchException> */
    private array $results = [];

    /** @var list<string> */
    public array $fetchedUrls = [];

    public int $secondsPerFetch = 0;

    public function __construct(private readonly ?MockClock $clock = null)
    {
    }

    public function willReturn(string $url, FetchResponse $response): void
    {
        $this->results[$url] = $response;
    }

    public function willThrow(string $url, FetchException $exception): void
    {
        $this->results[$url] = $exception;
    }

    public function fetch(string $url, ?string $etag = null, ?string $lastModified = null): FetchResponse
    {
        $this->fetchedUrls[] = $url;
        if ($this->secondsPerFetch > 0) {
            $this->clock?->sleep($this->secondsPerFetch);
        }

        $result = $this->results[$url] ?? throw new \LogicException('No stubbed result for ' . $url);
        if ($result instanceof FetchException) {
            throw $result;
        }

        return $result;
    }
}
