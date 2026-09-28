<?php

declare(strict_types=1);

namespace App\Service\Fetch\FeedFetcher;

use App\Service\Fetch\BatchFeedFetcher\BatchFeedFetcherInterface;
use App\Service\Fetch\Model\FetchResponseModel;
use App\Service\Fetch\Model\FetchTicketModel;

/**
 * Single-URL adapter over the batch engine, for the callers that genuinely want
 * one feed and can afford to block: discovery, preview, favicon resolution and
 * the backfill command.
 *
 * It delegates rather than implementing a second fetch loop on purpose — the
 * redirect and status-code rules are an SSRF control, and two copies of them
 * would drift.
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

        // Not a FetchException: the engine yields exactly one outcome per ticket,
        // so an empty result is a broken batch implementation, not a bad feed.
        // Every caller of fetch() swallows FetchException as "this feed failed",
        // which would bury the defect behind four plausible-looking feed errors.
        throw new \LogicException(sprintf('%s: the fetcher returned no outcome.', $url));
    }
}
