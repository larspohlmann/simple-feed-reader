<?php

declare(strict_types=1);

namespace App\Service\Fetch;

use App\Service\Crypto\Exception\SecretUnreadableException;
use App\Service\Fetch\EgressProxySource\EgressProxySourceInterface;
use App\Service\Fetch\Exception\FeedUnreachableException;
use App\Service\Fetch\Exception\FetchException;
use App\Service\Fetch\Exception\ResponseTooLargeException;
use App\Service\Fetch\Model\ExchangeStepModel;
use App\Service\Fetch\Model\FetchAttemptModel;
use App\Service\Fetch\Model\FetchOutcomeModel;
use App\Service\Fetch\Model\RequestTicketModel;
use App\Service\Fetch\Pass\FetchQueue;
use App\Service\Fetch\Pass\HostSlots;
use App\Service\Fetch\ResponseExchange\ResponseExchangeInterface;
use App\Service\Fetch\Support\EgressOptions;
use Psr\Container\ContainerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireLocator;
use Symfony\Contracts\HttpClient\ChunkInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Sends many guarded requests at once over Symfony's multiplexing HTTP client and yields each outcome under its
 * ticket's key as it lands. SECURITY: every attempt, a failover retry or a followed redirect included, passes UrlGuard
 * and goes out pinned or proxied with `max_redirects` 0; the ticket's exchange reads the answer.
 */
final readonly class ConcurrentRequestEngine
{
    private const float TIMEOUT_SECONDS = 10.0;

    public function __construct(
        private HttpClientInterface $httpClient,
        private UrlGuard $urlGuard,
        private EgressProxySourceInterface $egressProxySource,
        private FetchRetryPolicy $retryPolicy,
        #[AutowireLocator('app.response_exchange', defaultIndexMethod: 'kind')]
        private ContainerInterface $exchanges,
        private int $concurrency,
        private string $userAgent,
    ) {
        // A cap below one opens no request, so an empty run would read as a clean one: the sweep's `remaining` never
        // decrements and the frontend polls forever on `partial`.
        if ($concurrency < 1) {
            throw new \InvalidArgumentException(
                sprintf('Concurrency must be at least 1, got %d.', $concurrency),
            );
        }
    }

    /**
     * A failed request arrives as a failed outcome, never a throw, so one bad ticket cannot abandon the others;
     * abandoning the generator cancels whatever is still in flight.
     *
     * @param iterable<int|string, RequestTicketModel> $tickets
     *
     * @return \Generator<int|string, FetchOutcomeModel>
     */
    public function sendAll(iterable $tickets, int $slotCapacity): \Generator
    {
        try {
            $batchProxy = $this->egressProxySource->egressProxy();
        } catch (SecretUnreadableException $exception) {
            // An unreadable proxy password fails every ticket, one outcome each: the sweep's `remaining` only
            // decrements on a yielded outcome, so letting the exception escape would strand the run.
            yield from $this->failEvery($tickets, $exception);

            return;
        }

        // More full-slot candidates than there are slots cannot open a request this pass.
        $lookAhead = $this->concurrency;
        $queue = new FetchQueue($this->iterator($tickets), new HostSlots($slotCapacity), $lookAhead, $batchProxy);
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
            } catch (FetchException $exception) {
                $fallback = $this->retryPolicy->directFallbackFor($attempt);
                if (null !== $fallback) {
                    $queue->requeue($fallback);
                    continue;
                }

                yield $attempt->key => FetchOutcomeModel::failed($exception);
                continue;
            }

            $queue->onSent($attempt);
            $inFlight[$response] = $attempt;
        }
    }

    /**
     * Streams until one response resolves, then returns so the freed slot is refilled. A follow-up attempt goes back
     * on the queue instead of being sent inline, so a feed on its fourth hop shares the loop with one on its first.
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
                $step = $this->advance($response, $chunk, $attempt);
            } catch (FetchException $exception) {
                $this->retire($queue, $inFlight, $response);

                $requeue = $this->retryPolicy->nextAttemptAfter($attempt, $exception);
                if (null !== $requeue) {
                    $queue->requeue($requeue);

                    return;
                }

                yield $attempt->key => FetchOutcomeModel::failed($exception);

                return;
            }

            if ($step->isAwaitingBody()) {
                continue;
            }

            $this->retire($queue, $inFlight, $response);

            if (null !== $step->nextAttempt) {
                $queue->requeue($step->nextAttempt);

                return;
            }

            \assert(null !== $step->outcome);

            yield $attempt->key => $step->outcome;

            return;
        }
    }

    /** @throws FetchException */
    private function advance(
        ResponseInterface $response,
        ChunkInterface $chunk,
        FetchAttemptModel $attempt,
    ): ExchangeStepModel {
        $exchange = $this->exchangeFor($attempt);

        try {
            // Order is load-bearing: on a timeout chunk isFirst() throws while isTimeout() returns true, so asking
            // isFirst() first would report every timeout as a transport failure. On an error chunk isTimeout() throws,
            // and the catch below keeps the real cause.
            if ($chunk->isTimeout()) {
                throw new FeedUnreachableException(sprintf('%s: timed out', $attempt->url));
            }

            if ($chunk->isFirst()) {
                return $exchange->onHeaders($response, $attempt);
            }

            return $chunk->isLast()
                ? ExchangeStepModel::answered($exchange->onBody($response, $attempt))
                : ExchangeStepModel::awaitBody();
        } catch (ExceptionInterface $exception) {
            throw FetchException::from($attempt->url, $exception);
        }
    }

    private function exchangeFor(FetchAttemptModel $attempt): ResponseExchangeInterface
    {
        $exchange = $this->exchanges->get($attempt->ticket->exchange->value);
        \assert($exchange instanceof ResponseExchangeInterface);

        return $exchange;
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
        $request = $attempt->ticket->request;
        $sizeLimit = $attempt->ticket->exchange->sizeLimit();

        try {
            return $this->httpClient->request($request->method, $attempt->url, [
                'headers' => $this->headersOf($attempt->ticket),
                'max_redirects' => 0,
                'timeout' => self::TIMEOUT_SECONDS,
                'max_duration' => self::TIMEOUT_SECONDS * 2,
                'on_progress' => static function (int $downloaded) use ($sizeLimit): void {
                    ResponseTooLargeException::throwIfExceeded($sizeLimit, $downloaded);
                },
                ...(null === $request->json ? [] : ['json' => $request->json]),
                ...$egress,
            ]);
        } catch (ExceptionInterface $exception) {
            throw FetchException::from($attempt->url, $exception);
        }
    }

    /** @return array<string, string> */
    private function headersOf(RequestTicketModel $ticket): array
    {
        return [
            'Accept' => $ticket->exchange->accept(),
            // Refuse transparent compression so the size limit (counted on the
            // wire in on_progress) also bounds the buffered body — a compressed
            // response would otherwise decompress unbounded before the size check.
            'Accept-Encoding' => 'identity',
            'User-Agent' => $this->userAgent,
            ...$ticket->request->headers,
        ];
    }

    /**
     * Adapts any iterable to the Iterator the queue needs. Being a generator
     * itself, it never materialises the batch — the queue pulls tickets one at a
     * time, and buffering them would defeat a lazy source.
     *
     * @param iterable<int|string, RequestTicketModel> $tickets
     *
     * @return \Iterator<int|string, RequestTicketModel>
     */
    private function iterator(iterable $tickets): \Iterator
    {
        yield from $tickets;
    }

    /**
     * @param iterable<int|string, RequestTicketModel> $tickets
     *
     * @return \Generator<int|string, FetchOutcomeModel>
     */
    private function failEvery(iterable $tickets, \Throwable $cause): \Generator
    {
        foreach ($tickets as $key => $ticket) {
            yield $key => FetchOutcomeModel::failed(new FeedUnreachableException(
                sprintf('%s: the instance egress proxy is unusable: %s', $ticket->request->url, $cause->getMessage()),
                previous: $cause,
            ));
        }
    }
}
