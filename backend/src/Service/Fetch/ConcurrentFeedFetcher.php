<?php

declare(strict_types=1);

namespace App\Service\Fetch;

use App\Service\Fetch\Exception\FeedUnreachableException;
use App\Service\Fetch\Exception\FetchException;
use App\Service\Fetch\Exception\ResponseTooLargeException;
use App\Service\Crypto\Exception\SecretUnreadableException;
use Symfony\Contracts\HttpClient\ChunkInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Fetches many feeds at once over Symfony's multiplexing HTTP client.
 *
 * The refresh sweep is network-wait-bound — measured at 5.37 s of waiting across
 * 24 feeds against 0.4 s of parsing — so the requests overlap while the caller
 * still processes results one at a time.
 */
final class ConcurrentFeedFetcher implements BatchFeedFetcherInterface
{
    private const float TIMEOUT_SECONDS = 10.0;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly UrlGuard $urlGuard,
        private readonly ResponseClassifier $classifier,
        private readonly int $concurrency,
        private readonly string $userAgent,
        private readonly ProxyEgressResolver $proxyEgressResolver,
        private readonly FetchRetryPolicy $retryPolicy,
    ) {
        // A cap below one opens no requests at all, and the engine would report
        // an empty run as a clean one: the sweep's `remaining` never decrements
        // and the frontend's poll loop recurses forever on `partial`. This value
        // is bound from a container parameter, so a typo has to fail loudly.
        if ($concurrency < 1) {
            throw new \InvalidArgumentException(
                sprintf('Concurrency must be at least 1, got %d.', $concurrency),
            );
        }
    }

    /**
     * @param iterable<int|string, FetchTicket> $tickets
     *
     * @return \Generator<int|string, FetchOutcome>
     */
    public function fetchAll(iterable $tickets): \Generator
    {
        try {
            $batchProxy = $this->proxyEgressResolver->resolve();
        } catch (SecretUnreadableException $e) {
            // The proxy is enabled but its stored password cannot be opened, so
            // no feed in this batch can be reached. Report that per feed instead
            // of letting it escape: the sweep's `remaining` only decrements on a
            // yielded outcome, so an abort here would strand the whole run.
            yield from $this->failEvery($tickets, $e);

            return;
        }

        $queue = new FetchQueue($this->iterator($tickets), $batchProxy);
        /** @var \SplObjectStorage<ResponseInterface, FetchAttempt> $inFlight */
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
     * Opens requests until the concurrency cap is reached or the queue dries up.
     * A URL the guard rejects never becomes a request, so it is reported here.
     *
     * @param \SplObjectStorage<ResponseInterface, FetchAttempt> $inFlight
     *
     * @return \Generator<int|string, FetchOutcome>
     */
    private function fill(FetchQueue $queue, \SplObjectStorage $inFlight): \Generator
    {
        while ($inFlight->count() < $this->concurrency && $queue->hasMore()) {
            $attempt = $queue->next();

            try {
                $inFlight[$this->send($attempt)] = $attempt;
            } catch (FetchException $e) {
                $fallback = $this->retryPolicy->directFallbackFor($attempt);
                if (null !== $fallback) {
                    $queue->requeue($fallback);
                    continue;
                }

                yield $attempt->key => FetchOutcome::failed($e);
            }
        }
    }

    /**
     * Streams the in-flight set until one response resolves, then returns so the
     * freed slot can be refilled. Redirects go back on the queue rather than
     * being followed inline, which is what lets a feed on its fourth hop share
     * the loop with one on its first.
     *
     * @param \SplObjectStorage<ResponseInterface, FetchAttempt> $inFlight
     *
     * @return \Generator<int|string, FetchOutcome>
     */
    private function awaitNext(FetchQueue $queue, \SplObjectStorage $inFlight): \Generator
    {
        foreach ($this->httpClient->stream($inFlight, self::TIMEOUT_SECONDS) as $response => $chunk) {
            $attempt = $inFlight[$response];

            try {
                $verdict = $this->advance($response, $chunk, $attempt);
            } catch (FetchException $e) {
                $this->retire($inFlight, $response);

                $requeue = $this->retryPolicy->nextAttemptAfter($attempt, $e);
                if (null !== $requeue) {
                    $queue->requeue($requeue);

                    return;
                }

                yield $attempt->key => FetchOutcome::failed($e);

                return;
            }

            if (null === $verdict) {
                continue;
            }

            $this->retire($inFlight, $response);

            if ($verdict instanceof FetchAttempt) {
                $queue->requeue($verdict);

                return;
            }

            yield $attempt->key => FetchOutcome::succeeded($verdict);

            return;
        }
    }

    /**
     * One chunk's worth of progress: null while the response is still arriving,
     * a FetchResponse when it is done, or the next FetchAttempt on a redirect.
     *
     * @throws FetchException
     */
    private function advance(
        ResponseInterface $response,
        ChunkInterface $chunk,
        FetchAttempt $attempt,
    ): FetchResponse|FetchAttempt|null {
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
    private function onHeaders(ResponseInterface $response, FetchAttempt $attempt): FetchResponse|FetchAttempt|null
    {
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
                FetchAttempt::MAX_REDIRECTS,
            ));
        }

        \assert(null !== $verdict->redirectUrl);

        return $attempt->followedTo($verdict->redirectUrl, $verdict->permanent);
    }

    /** @param \SplObjectStorage<ResponseInterface, FetchAttempt> $inFlight */
    private function retire(\SplObjectStorage $inFlight, ResponseInterface $response): void
    {
        $inFlight->detach($response);
        $response->cancel();
    }

    /** @throws FetchException when the URL fails the SSRF guard */
    private function send(FetchAttempt $attempt): ResponseInterface
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
    private function headers(FetchTicket $ticket): array
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
     * @param iterable<int|string, FetchTicket> $tickets
     *
     * @return \Iterator<int|string, FetchTicket>
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
     * @param iterable<int|string, FetchTicket> $tickets
     *
     * @return \Generator<int|string, FetchOutcome>
     */
    private function failEvery(iterable $tickets, \Throwable $cause): \Generator
    {
        foreach ($tickets as $key => $ticket) {
            yield $key => FetchOutcome::failed(new FeedUnreachableException(
                sprintf('%s: the instance egress proxy is unusable: %s', $ticket->url, $cause->getMessage()),
                previous: $cause,
            ));
        }
    }
}
