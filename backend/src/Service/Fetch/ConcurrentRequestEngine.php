<?php

declare(strict_types=1);

namespace App\Service\Fetch;

use App\Service\Crypto\Exception\SecretUnreadableException;
use App\Service\Fetch\AnswerSettler\AnswerSettlerInterface;
use App\Service\Fetch\EgressProxySource\EgressProxySourceInterface;
use App\Service\Fetch\Exception\FeedUnreachableException;
use App\Service\Fetch\Exception\FetchException;
use App\Service\Fetch\Exception\ResponseTooLargeException;
use App\Service\Fetch\Model\ExchangeStepModel;
use App\Service\Fetch\Model\FetchAttemptModel;
use App\Service\Fetch\Model\FetchOutcomeModel;
use App\Service\Fetch\Model\RequestTicketModel;
use App\Service\Fetch\Pass\FetchQueue;
use App\Service\Fetch\Pass\RequestSlots;
use App\Service\Fetch\Pass\SettledAnswers;
use App\Service\Fetch\ResponseExchange\ResponseExchangeInterface;
use App\Service\Fetch\Support\CapacityGuard;
use App\Service\Fetch\Support\EgressOptions;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireLocator;
use Symfony\Contracts\HttpClient\ChunkInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Sends many guarded requests at once over Symfony's multiplexing HTTP client and yields each settled outcome under its
 * ticket's key as it lands. SECURITY: every attempt, a failover retry, a followed redirect or a follow-up included,
 * passes UrlGuard and goes out pinned or proxied with `max_redirects` 0; the ticket's exchange reads the answer.
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
        CapacityGuard::atLeastOne($concurrency, 'Concurrency');
    }

    /**
     * A failed request arrives as a failed outcome, never a throw, so one bad ticket cannot abandon the others;
     * abandoning the generator cancels whatever is still in flight, follow-ups included. A request goes out only while
     * every slot it names has room and the settler admits it, and every raw outcome is settled before it is yielded.
     *
     * @template T
     *
     * @param iterable<int|string, RequestTicketModel> $tickets
     * @param AnswerSettlerInterface<T>                $settler
     *
     * @return \Generator<int|string, T>
     */
    public function sendAll(iterable $tickets, AnswerSettlerInterface $settler): \Generator
    {
        try {
            $batchProxy = $this->egressProxySource->egressProxy();
        } catch (SecretUnreadableException $exception) {
            // An unreadable proxy password fails every ticket, one outcome each: the sweep's `remaining` only
            // decrements on a yielded outcome, so letting the exception escape would strand the run.
            yield from $this->failEvery($tickets, $exception, $settler);

            return;
        }

        // More full-slot candidates than there are slots cannot open a request this pass.
        $lookAhead = $this->concurrency;
        $queue = new FetchQueue($this->iterator($tickets), new RequestSlots(), $lookAhead, $batchProxy);
        $answers = new SettledAnswers($settler, $queue);
        /** @var \SplObjectStorage<ResponseInterface, FetchAttemptModel> $inFlight */
        $inFlight = new \SplObjectStorage();

        try {
            while (true) {
                yield from $this->fill($queue, $inFlight, $answers);

                if (0 === $inFlight->count()) {
                    return;
                }

                yield from $this->awaitNext($queue, $inFlight, $answers);
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
     * @template T
     *
     * @param \SplObjectStorage<ResponseInterface, FetchAttemptModel> $inFlight
     * @param SettledAnswers<T>                                       $answers
     *
     * @return \Generator<int|string, T>
     */
    private function fill(FetchQueue $queue, \SplObjectStorage $inFlight, SettledAnswers $answers): \Generator
    {
        while ($inFlight->count() < $this->concurrency) {
            $attempt = $queue->takeRunnable();
            if (null === $attempt) {
                return;
            }
            if (!$answers->admits($attempt)) {
                yield from $answers->settle($attempt->key, FetchOutcomeModel::withheld());
                continue;
            }

            try {
                $response = $this->send($attempt);
            } catch (FetchException $exception) {
                $fallback = $this->retryPolicy->directFallbackFor($attempt);
                if (null !== $fallback) {
                    $queue->requeue($fallback);
                    continue;
                }

                yield from $answers->settle($attempt->key, FetchOutcomeModel::failed($exception));
                continue;
            }

            $queue->onSent($attempt);
            $inFlight[$response] = $attempt;
        }
    }

    /**
     * Streams until one response resolves, then returns so the freed slot is refilled. A redirect or a retry goes back
     * on the queue instead of being sent inline, so a feed on its fourth hop shares the loop with one on its first.
     *
     * @template T
     *
     * @param \SplObjectStorage<ResponseInterface, FetchAttemptModel> $inFlight
     * @param SettledAnswers<T>                                       $answers
     *
     * @return \Generator<int|string, T>
     */
    private function awaitNext(FetchQueue $queue, \SplObjectStorage $inFlight, SettledAnswers $answers): \Generator
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

                yield from $answers->settle($attempt->key, FetchOutcomeModel::failed($exception));

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

            yield from $answers->settle($attempt->key, $step->outcome);

            return;
        }
    }

    /** @throws FetchException */
    private function advance(
        ResponseInterface $response,
        ChunkInterface $chunk,
        FetchAttemptModel $attempt,
    ): ExchangeStepModel {
        try {
            $exchange = $this->exchangeFor($attempt);
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
                ? ExchangeStepModel::concluded($exchange->onBody($response, $attempt))
                : ExchangeStepModel::awaitBody();
        } catch (ExceptionInterface $exception) {
            throw FetchException::from($attempt->url, $exception);
        }
    }

    /** @throws FeedUnreachableException when no exchange reads the ticket's kind of answer */
    private function exchangeFor(FetchAttemptModel $attempt): ResponseExchangeInterface
    {
        $kind = $attempt->ticket->exchange->value;
        try {
            $exchange = $this->exchanges->get($kind);
        } catch (ContainerExceptionInterface $exception) {
            throw new FeedUnreachableException(
                sprintf('%s: no exchange reads a %s answer', $attempt->url, $kind),
                previous: $exception,
            );
        }
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

    /**
     * The engine's headers win over a ticket's in any letter case: a differently cased copy goes out as a second value.
     *
     * @return array<string, string>
     */
    private function headersOf(RequestTicketModel $ticket): array
    {
        $engineOwned = [
            'Accept' => $ticket->exchange->accept(),
            // Refuse transparent compression so the size limit (counted on the
            // wire in on_progress) also bounds the buffered body — a compressed
            // response would otherwise decompress unbounded before the size check.
            'Accept-Encoding' => 'identity',
            'User-Agent' => $this->userAgent,
        ];
        $ownedNames = array_change_key_case($engineOwned);
        $ticketOwned = array_filter(
            $ticket->request->headers,
            static fn (string $name): bool => !isset($ownedNames[strtolower($name)]),
            \ARRAY_FILTER_USE_KEY,
        );

        return [...$engineOwned, ...$ticketOwned];
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
     * @template T
     *
     * @param iterable<int|string, RequestTicketModel> $tickets
     * @param AnswerSettlerInterface<T>                $settler
     *
     * @return \Generator<int|string, T>
     *
     * @throws \LogicException when the settler answers a failure with a follow-up: there is no queue to send it on
     */
    private function failEvery(iterable $tickets, \Throwable $cause, AnswerSettlerInterface $settler): \Generator
    {
        foreach ($tickets as $key => $ticket) {
            $failure = FetchOutcomeModel::failed(new FeedUnreachableException(
                sprintf('%s: the instance egress proxy is unusable: %s', $ticket->request->url, $cause->getMessage()),
                previous: $cause,
            ));

            yield $key => $settler->settle($key, $failure)->settledOutcome();
        }
    }
}
