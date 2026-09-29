<?php

declare(strict_types=1);

namespace App\Service\Fetch\BatchFeedFetcher;

use App\Service\Crypto\Exception\SecretUnreadableException;
use App\Service\Fetch\EgressProxySource\EgressProxySourceInterface;
use App\Service\Fetch\Exception\FeedUnreachableException;
use App\Service\Fetch\Exception\FetchException;
use App\Service\Fetch\Exception\ResponseTooLargeException;
use App\Service\Fetch\FetchRetryPolicy;
use App\Service\Fetch\Model\FetchAttemptModel;
use App\Service\Fetch\Model\FetchOutcomeModel;
use App\Service\Fetch\Model\FetchResponseModel;
use App\Service\Fetch\Model\FetchTicketModel;
use App\Service\Fetch\Model\HeaderDecision;
use App\Service\Fetch\Pass\FetchQueue;
use App\Service\Fetch\Pass\HostSlots;
use App\Service\Fetch\ResponseClassifier;
use App\Service\Fetch\Support\EgressOptions;
use App\Service\Fetch\UrlGuard;
use Symfony\Contracts\HttpClient\ChunkInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Fetches many feeds at once over Symfony's multiplexing HTTP client; the refresh
 * sweep is network-wait-bound, so requests overlap while the caller still
 * processes results one at a time.
 */
final readonly class ConcurrentFeedFetcher implements BatchFeedFetcherInterface
{
    private const float TIMEOUT_SECONDS = 10.0;

    public function __construct(
        private HttpClientInterface $httpClient,
        private UrlGuard $urlGuard,
        private ResponseClassifier $classifier,
        private int $concurrency,
        private int $hostConcurrency,
        private string $userAgent,
        private EgressProxySourceInterface $egressProxySource,
        private FetchRetryPolicy $retryPolicy,
    ) {
        // A cap below one opens no requests at all, and the engine would report
        // an empty run as a clean one: the sweep's `remaining` never decrements
        // and the frontend's poll loop recurses forever on `partial`. These are
        // bound from container parameters, so a typo has to fail loudly.
        if ($concurrency < 1) {
            throw new \InvalidArgumentException(
                sprintf('Concurrency must be at least 1, got %d.', $concurrency),
            );
        }
        if ($hostConcurrency < 1) {
            throw new \InvalidArgumentException(
                sprintf('Per-host concurrency must be at least 1, got %d.', $hostConcurrency),
            );
        }
    }

    /**
     * @param iterable<int|string, FetchTicketModel> $tickets
     *
     * @return \Generator<int|string, FetchOutcomeModel>
     */
    public function fetchAll(iterable $tickets): \Generator
    {
        try {
            $batchProxy = $this->egressProxySource->egressProxy();
        } catch (SecretUnreadableException $e) {
            // The proxy is enabled but its stored password cannot be opened, so
            // no feed in this batch can be reached. Report that per feed instead
            // of letting it escape: the sweep's `remaining` only decrements on a
            // yielded outcome, so an abort here would strand the whole run.
            yield from $this->failEvery($tickets, $e);

            return;
        }

        // Look no further past a full host than there are slots to fill: staging
        // more full-host candidates than that cannot open a request this pass, and
        // it would advance — and so commit — the budget-gated ticket source for
        // feeds no slot has opened for.
        $lookAhead = $this->concurrency;
        $hostSlots = new HostSlots($this->hostConcurrency);
        $queue = new FetchQueue($this->iterator($tickets), $hostSlots, $lookAhead, $batchProxy);
        /** @var \SplObjectStorage<ResponseInterface, FetchAttemptModel> $inFlight */
        $inFlight = new \SplObjectStorage();

        try {
            while (true) {
                yield from $this->fill($queue, $inFlight);

                if (0 === $inFlight->count()) {
                    return;
                }

                yield from $this->awaitNext($queue, $inFlight);
            }
        } finally {
            // Reached on `break` as well as on completion: an aborted run must
            // not leave sockets open behind the caller's back.
            foreach ($inFlight as $response) {
                $response->cancel();
            }
        }
    }

    /**
     * Opens requests until the concurrency cap is reached or no queued attempt
     * can run now — the queue returns null both when it is empty and when every
     * remaining attempt's host is at capacity, in which case a freed slot has to
     * come from an in-flight response. A URL the guard rejects never becomes a
     * request, so it is reported here. The host slot is claimed on the queue the
     * moment the request goes on the wire and released when its response retires.
     *
     * @param \SplObjectStorage<ResponseInterface, FetchAttemptModel> $inFlight
     *
     * @return \Generator<int|string, FetchOutcomeModel>
     */
    private function fill(FetchQueue $queue, \SplObjectStorage $inFlight): \Generator
    {
        while ($inFlight->count() < $this->concurrency) {
            $attempt = $queue->takeRunnable();
            if (null === $attempt) {
                return;
            }

            try {
                $response = $this->send($attempt);
            } catch (FetchException $e) {
                $fallback = $this->retryPolicy->directFallbackFor($attempt);
                if (null !== $fallback) {
                    $queue->requeue($fallback);
                    continue;
                }

                yield $attempt->key => FetchOutcomeModel::failed($e);
                continue;
            }

            $queue->onSent($attempt);
            $inFlight[$response] = $attempt;
        }
    }

    /**
     * Streams the in-flight set until one response resolves, then returns so the
     * freed slot can be refilled. Redirects go back on the queue rather than
     * being followed inline, which is what lets a feed on its fourth hop share
     * the loop with one on its first.
     *
     * @param \SplObjectStorage<ResponseInterface, FetchAttemptModel> $inFlight
     *
     * @return \Generator<int|string, FetchOutcomeModel>
     */
    private function awaitNext(FetchQueue $queue, \SplObjectStorage $inFlight): \Generator
    {
        foreach ($this->httpClient->stream($inFlight, self::TIMEOUT_SECONDS) as $response => $chunk) {
            $attempt = $inFlight[$response];

            try {
                $verdict = $this->advance($response, $chunk, $attempt);
            } catch (FetchException $e) {
                $this->retire($queue, $inFlight, $response);

                $requeue = $this->retryPolicy->nextAttemptAfter($attempt, $e);
                if (null !== $requeue) {
                    $queue->requeue($requeue);

                    return;
                }

                yield $attempt->key => FetchOutcomeModel::failed($e);

                return;
            }

            if (null === $verdict) {
                continue;
            }

            $this->retire($queue, $inFlight, $response);

            if ($verdict instanceof FetchAttemptModel) {
                $queue->requeue($verdict);

                return;
            }

            yield $attempt->key => FetchOutcomeModel::succeeded($verdict);

            return;
        }
    }

    /**
     * One chunk's worth of progress: null while the response is still arriving,
     * a FetchResponseModel when it is done, or the next FetchAttemptModel on a redirect.
     *
     * @throws FetchException
     */
    private function advance(
        ResponseInterface $response,
        ChunkInterface $chunk,
        FetchAttemptModel $attempt,
    ): FetchResponseModel|FetchAttemptModel|null {
        try {
            // Order is load-bearing. On a timeout ErrorChunk isTimeout() returns
            // true while isFirst() throws, so asking isFirst() first would report
            // every timeout as a generic transport failure. On an error chunk
            // isTimeout() throws instead, which the catch below turns into the
            // message carrying the real cause.
            if ($chunk->isTimeout()) {
                throw new FeedUnreachableException(sprintf('%s: timed out', $attempt->url));
            }

            if ($chunk->isFirst()) {
                return $this->onHeaders($response, $attempt);
            }

            return $chunk->isLast() ? $this->classifier->fromBody($response, $attempt) : null;
        } catch (ExceptionInterface $e) {
            throw FetchException::from($attempt->url, $e);
        }
    }

    /** @throws FetchException */
    private function onHeaders(
        ResponseInterface $response,
        FetchAttemptModel $attempt,
    ): FetchResponseModel|FetchAttemptModel|null {
        $verdict = $this->classifier->fromHeaders($response, $attempt);

        if (HeaderDecision::AwaitBody === $verdict->decision) {
            return null;
        }

        if (HeaderDecision::Terminal === $verdict->decision) {
            \assert(null !== $verdict->response);

            return $verdict->response;
        }

        if (!$attempt->canFollowRedirect()) {
            throw new FeedUnreachableException(sprintf(
                '%s: more than %d redirects',
                $attempt->ticket->url,
                FetchAttemptModel::MAX_REDIRECTS,
            ));
        }

        \assert(null !== $verdict->redirectUrl);

        return $attempt->followedTo($verdict->redirectUrl, $verdict->permanent);
    }

    /** @param \SplObjectStorage<ResponseInterface, FetchAttemptModel> $inFlight */
    private function retire(FetchQueue $queue, \SplObjectStorage $inFlight, ResponseInterface $response): void
    {
        $queue->onRetired($inFlight[$response]);
        $inFlight->detach($response);
        $response->cancel();
    }

    /** @throws FetchException when the URL fails the SSRF guard */
    private function send(FetchAttemptModel $attempt): ResponseInterface
    {
        $proxy = $attempt->proxy;

        // The host guard runs on both paths; only the IP pin is proxy-incompatible.
        $guarded = $this->urlGuard->assertSafe($attempt->url);

        $egress = null !== $proxy
            ? EgressOptions::proxied($proxy)
            : EgressOptions::pinned($guarded, $attempt->pinnedAddressAttempt);

        try {
            return $this->httpClient->request('GET', $attempt->url, [
                'headers' => $this->headers($attempt->ticket),
                'max_redirects' => 0,
                'timeout' => self::TIMEOUT_SECONDS,
                'max_duration' => self::TIMEOUT_SECONDS * 2,
                'on_progress' => static function (int $downloaded): void {
                    ResponseTooLargeException::throwIfExceeded($downloaded);
                },
                ...$egress,
            ]);
        } catch (ExceptionInterface $e) {
            throw FetchException::from($attempt->url, $e);
        }
    }

    /** @return array<string, string> */
    private function headers(FetchTicketModel $ticket): array
    {
        $headers = [
            'Accept' => 'application/rss+xml, application/atom+xml, application/xml;q=0.9, text/xml;q=0.8, */*;q=0.1',
            // Refuse transparent compression so the MAX_BYTES cap (counted on the
            // wire in on_progress) also bounds the buffered body — a compressed
            // response would otherwise decompress unbounded before the size check.
            'Accept-Encoding' => 'identity',
            'User-Agent' => $this->userAgent,
        ];
        if (null !== $ticket->etag) {
            $headers['If-None-Match'] = $ticket->etag;
        }
        if (null !== $ticket->lastModified) {
            $headers['If-Modified-Since'] = $ticket->lastModified;
        }

        return $headers;
    }

    /**
     * Adapts any iterable to the Iterator the queue needs. Being a generator
     * itself, it never materialises the batch — the queue pulls tickets one at a
     * time, and buffering them would defeat a lazy source.
     *
     * @param iterable<int|string, FetchTicketModel> $tickets
     *
     * @return \Iterator<int|string, FetchTicketModel>
     */
    private function iterator(iterable $tickets): \Iterator
    {
        yield from $tickets;
    }

    /**
     * Every ticket in the batch, failed with the same cause. Used when the
     * egress cannot be resolved at all, which is a property of the run rather
     * than of any one feed.
     *
     * @param iterable<int|string, FetchTicketModel> $tickets
     *
     * @return \Generator<int|string, FetchOutcomeModel>
     */
    private function failEvery(iterable $tickets, \Throwable $cause): \Generator
    {
        foreach ($tickets as $key => $ticket) {
            yield $key => FetchOutcomeModel::failed(new FeedUnreachableException(
                sprintf('%s: the instance egress proxy is unusable: %s', $ticket->url, $cause->getMessage()),
                previous: $cause,
            ));
        }
    }
}
