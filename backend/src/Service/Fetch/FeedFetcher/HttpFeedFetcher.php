<?php

declare(strict_types=1);

namespace App\Service\Fetch\FeedFetcher;

use App\Service\Fetch\BatchFeedFetcher\BatchFeedFetcherInterface;
use App\Service\Fetch\Model\FetchResponseModel;
use App\Service\Fetch\Model\FetchTicketModel;

/**
 * Single-URL adapter over the batch engine, for the callers that can block on one feed. It delegates instead of
 * running a second fetch loop: the redirect and status rules are an SSRF control, and two copies would drift.
 */
final readonly class HttpFeedFetcher implements FeedFetcherInterface
{
    public function __construct(private BatchFeedFetcherInterface $fetcher)
    {
    }

    public function fetch(string $url): FetchResponseModel
    {
        foreach ($this->fetcher->fetchAll([new FetchTicketModel($url)]) as $outcome) {
            return $outcome->responseOrThrow();
        }

        // A LogicException, not a FetchException: every caller swallows FetchException as "this feed failed", and an
        // empty result is a broken engine (it yields one outcome per ticket), not a bad feed.
        throw new \LogicException(sprintf('%s: the fetcher returned no outcome.', $url));
    }
}
