<?php

declare(strict_types=1);

namespace App\Service\Recommendation;

use App\Service\Ai\Exception\CredentialsRejectedException;
use App\Service\Ai\Exception\ProviderRunawayException;
use App\Service\Ai\Exception\ProviderUnreachableException;
use App\Service\Ai\ProviderConnection;
use Symfony\Contracts\HttpClient\ChunkInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Sends `POST {baseUrl}/chat/completions`, the one call a tick makes to turn
 * a prompt into a ranking.
 *
 * The caps are not an SSRF boundary — see ProviderCredentials for why there is
 * none — they keep one hostile or broken endpoint from holding a request open
 * or filling memory.
 */
final readonly class OpenAiCompatibleChatClient implements ChatCompletionClient
{
    // The wall clock and the first-byte bound now travel with the connection
    // (ProviderTimeouts), because one pair of numbers cannot serve a hosted
    // endpoint and a slow local one at once — see that class for the numbers
    // and the history behind them (#433).

    /**
     * How many bytes of retained answer one requested token buys.
     *
     * The bound this sizes is what stops a provider that ignores `max_tokens`
     * and generates until something else stops it. It used to be a flat 2 MB,
     * which could never fire: the largest answer a request may ask for is a
     * few tens of kilobytes, so `max_tokens` always came first and the guard
     * was dead on the one path it existed for (#437).
     *
     * Eight bytes a token is twice the prompt builder's own estimate, so a
     * reply the request legitimately asked for cannot trip it — the largest
     * full batch on record retained 12 KB against a 36 KB bound.
     */
    private const int RETAINED_BYTES_PER_REQUESTED_TOKEN = 8;

    // What the provider is allowed to send. This is a runaway guard, not a
    // memory bound — the reader discards each event once decoded, so wire
    // bytes no longer accumulate. It has to clear reasoning: a single #320
    // call legitimately spent 1.9 MB of stream before answering.
    private const int MAXIMUM_WIRE_BYTES = 67_108_864;


    public function __construct(
        private HttpClientInterface $httpClient,
        private CompletionBodyDecoder $decoder,
        private CompletionStreamHeartbeat $heartbeat,
        private string $userAgent,
    ) {
    }

    public function complete(
        ProviderConnection $connection,
        CompletionRequest $request,
        CompletionStreamObserver $observer,
    ): string {
        // A single-call wave through the same concurrent path (#344): one
        // call is just completeMany() with a call list of one, so the two
        // never drift on how a chunk is read, a status is guarded, or an
        // answer is recovered.
        $outcome = $this->completeMany($connection, [new ConcurrentCompletion($request, $observer)])[0];
        if ($outcome->isFailure()) {
            throw $outcome->cause();
        }

        return $outcome->content();
    }

    public function completeMany(ProviderConnection $connection, array $calls): array
    {
        // Each response carries its own reader (its parsing state) and its own
        // observer. SplObjectStorage maps the response object the stream() loop
        // yields back to those and to the $calls index, so the outcomes stay
        // aligned however the concurrent streams interleave. fireRequests()
        // already seeded $outcomes for any call that never made it to a
        // response at all, so a request-phase failure and a stream-phase one
        // both end up in the same per-call outcome shape.
        [$context, $outcomes] = $this->fireRequests($connection, $calls);

        $responses = $this->httpClient->stream(
            $this->responsesIn($context),
            $connection->timeouts->firstByteSeconds,
        );
        foreach ($responses as $response => $chunk) {
            // Every chunk, including the framing ones that carry no content:
            // this says the process reading the stream is alive, and that is
            // true of a keep-alive marker exactly as much as of a delta.
            $this->heartbeat->beat();

            $slot = $context[$response];

            // A failed call cancels its response but the loop may still yield a
            // trailing chunk for it; a finished call is likewise done. Ignore
            // both — this call already has its outcome.
            if (null !== $outcomes[$slot->index]) {
                continue;
            }

            $outcomes[$slot->index] = $this->advance($response, $chunk, $slot);
        }

        return $this->settleOutstanding($outcomes);
    }

    /**
     * Fires every request up front — Symfony starts the transfer on request(),
     * so this is what makes the reads concurrent — and records the per-response
     * state the multiplexed loop needs to route each chunk. request() itself is
     * not part of the multiplexed read that advance() guards, so a transport
     * failure here (a refused connection, say) is caught right here instead:
     * wrapped the same way complete() has always wrapped it, and banked
     * straight into that call's own outcome. That keeps every call independent
     * even at this earlier stage — one call's connection refusal does not stop
     * its siblings' requests from going out, matching the rest of this class's
     * per-call failure story (#344).
     *
     * @param non-empty-list<ConcurrentCompletion> $calls
     *
     * @return array{0: \SplObjectStorage<ResponseInterface, CompletionCallSlot>, 1: list<?CompletionOutcome>}
     */
    private function fireRequests(ProviderConnection $connection, array $calls): array
    {
        /** @var \SplObjectStorage<ResponseInterface, CompletionCallSlot> $context */
        $context = new \SplObjectStorage();
        /** @var list<?CompletionOutcome> $outcomes */
        $outcomes = array_fill(0, \count($calls), null);

        foreach ($calls as $index => $call) {
            try {
                $response = $this->request($connection, $call->request);
            } catch (ExceptionInterface $e) {
                $outcomes[$index] = CompletionOutcome::failure(
                    new ProviderUnreachableException('That address did not answer.', 0, $e),
                );

                continue;
            }

            $context[$response] = new CompletionCallSlot(
                $index,
                new CompletionStreamReader($this->decoder),
                $call->observer,
                $connection->timeouts,
                $call->request->maxAnswerTokens * self::RETAINED_BYTES_PER_REQUESTED_TOKEN,
            );
        }

        return [$context, array_values($outcomes)];
    }

    /**
     * @param \SplObjectStorage<ResponseInterface, CompletionCallSlot> $context
     *
     * @return list<ResponseInterface>
     */
    private function responsesIn(\SplObjectStorage $context): array
    {
        return iterator_to_array($context, false);
    }

    /**
     * Reads one chunk of one call's stream and settles that call when it ends.
     * A per-call transport failure becomes that call's failure outcome rather
     * than an exception, so it never aborts the read for its siblings (#344);
     * null means the call is still in progress.
     */
    private function advance(
        ResponseInterface $response,
        ChunkInterface $chunk,
        CompletionCallSlot $slot,
    ): ?CompletionOutcome {
        try {
            if (!$this->consumeChunk($response, $chunk, $slot)) {
                return null;
            }

            return CompletionOutcome::answer($this->contentOf($slot->reader));
        } catch (CredentialsRejectedException | ProviderUnreachableException | ProviderRunawayException $failure) {
            $response->cancel();

            return CompletionOutcome::failure($failure);
        } catch (ExceptionInterface $transportFailure) {
            $response->cancel();

            return CompletionOutcome::failure($this->transportFailureOf($slot, $transportFailure));
        }
    }

    /**
     * A call that reached `max_tokens` and was then cut by the wall clock is a
     * runaway, not an unreachable address. `length` is the provider's own word
     * for "I stopped because the ceiling stopped me", and a model that spends
     * the whole ceiling repeating itself takes the wall clock with it — the
     * call that prompted #437 reported `length`, streamed 8.2 MB, and was
     * still reported as "That address did not answer".
     *
     * Everything else stays unreachable. A connection reset mid-answer is a
     * dead connection however many bytes preceded it, and guessing otherwise
     * from a byte count would relabel the ordinary failure this message is
     * for.
     */
    private function transportFailureOf(CompletionCallSlot $slot, ExceptionInterface $failure): \RuntimeException
    {
        if ('length' !== $slot->reader->finishReason()) {
            return new ProviderUnreachableException('That address did not answer.', 0, $failure);
        }

        return new ProviderRunawayException(
            sprintf(
                'That provider spent its whole %d-token ceiling and did not stop, %d bytes in.',
                intdiv($slot->maximumAnswerBytes, self::RETAINED_BYTES_PER_REQUESTED_TOKEN),
                $slot->reader->wireBytes(),
            ),
            $slot->reader->assistantContent() ?? '',
        );
    }

    /**
     * A response that never yields a closing chunk (it should always) still
     * owes the caller one outcome per call, so an unsettled slot becomes the
     * same answerless failure an empty completion does.
     *
     * @param list<?CompletionOutcome> $outcomes
     *
     * @return list<CompletionOutcome>
     */
    private function settleOutstanding(array $outcomes): array
    {
        return array_map(
            static fn (?CompletionOutcome $outcome): CompletionOutcome => $outcome ?? CompletionOutcome::failure(
                new ProviderUnreachableException('That provider answered without a completion.'),
            ),
            $outcomes,
        );
    }

    /**
     * The per-chunk core shared by the single-call and concurrent reads. Feeds
     * one chunk to the reader and reports it to the observer; returns true once
     * the response is complete.
     *
     * @throws CredentialsRejectedException
     * @throws ProviderUnreachableException
     * @throws ExceptionInterface
     */
    private function consumeChunk(ResponseInterface $response, ChunkInterface $chunk, CompletionCallSlot $slot): bool
    {
        $reader = $slot->reader;

        // isTimeout() first — on a timeout chunk the other accessors throw;
        // same ordering hazard ConcurrentFeedFetcher documents. The other
        // direction matters too: on a non-timeout error chunk isTimeout()
        // itself throws, and that is how max_duration exhaustion leaves here as
        // the generic "did not answer".
        if ($chunk->isTimeout()) {
            $response->cancel();

            // Shape-neutral on purpose: the provider may have gone silent
            // mid-answer or never have started, and this bound covers both.
            // %s over the float renders "180" today and keeps the number tied
            // to the constant even if it ever became fractional.
            throw new ProviderUnreachableException(sprintf(
                'That provider sent nothing for more than %s seconds.',
                $slot->timeouts->firstByteSeconds,
            ));
        }

        // Headers have arrived: the status is readable here without blocking,
        // which is also the only point the concurrent read can inspect it.
        if ($chunk->isFirst()) {
            $this->guardStatus($response->getStatusCode());
        }

        // Symfony's own stream() protocol includes content-free framing chunks
        // (the isFirst and isLast markers in particular) alongside the real SSE
        // data — appending their empty content is harmless, but reporting them
        // to the observer would mean "the body grew" for a chunk that added
        // nothing.
        $content = $chunk->getContent();
        if ('' !== $content) {
            $reader->consume($content);
            $this->guardRetainedSize($slot);
            $slot->observer->streamProgressed(new CompletionStreamProgress(
                $reader->assistantContent() ?? '',
                $reader->wireBytes(),
                $reader->finishReason(),
                $reader->usage(),
            ));
        }

        return $chunk->isLast();
    }

    private function guardStatus(int $status): void
    {
        if (401 === $status || 403 === $status) {
            throw new CredentialsRejectedException('That provider refused the API key.');
        }

        if ($status >= 300) {
            throw new ProviderUnreachableException(sprintf('That provider answered with status %d.', $status));
        }
    }

    /**
     * Prefer the answer channel; fall back to the reasoning channel only when
     * it is empty. LM Studio routes some models' whole answer through
     * reasoning_content and never fills content (#323), and the answer is
     * recoverable from there — the parser still validates whatever comes back,
     * so a reply that is only thinking is rejected downstream.
     */
    private function contentOf(CompletionStreamReader $reader): string
    {
        $content = $reader->assistantContent() ?? $reader->reasoningContent();

        if (null === $content) {
            throw new ProviderUnreachableException('That provider answered without a completion.');
        }

        return $content;
    }

    private function guardRetainedSize(CompletionCallSlot $slot): void
    {
        if ($slot->reader->retainedBytes() <= $slot->maximumAnswerBytes) {
            return;
        }

        throw new ProviderRunawayException(
            sprintf('That provider answered with more than %d bytes.', $slot->maximumAnswerBytes),
            $slot->reader->assistantContent() ?? '',
        );
    }

    private function request(ProviderConnection $connection, CompletionRequest $request): ResponseInterface
    {
        return $this->httpClient->request('POST', $connection->credentials->baseUrl . '/chat/completions', [
            'headers' => [
                'Accept' => 'text/event-stream, application/json',
                // Refuse transparent compression so the wire cap below also bounds
                // the buffered body — gzip would otherwise let a small reply
                // decompress unbounded after the cap has already passed it. Same
                // reasoning as ConcurrentFeedFetcher::headers().
                'Accept-Encoding' => 'identity',
                'User-Agent' => $this->userAgent,
                ...$connection->credentials->authorizationHeaders(),
            ],
            'json' => $this->completionPayload($request),
            // Idle bound only: with a streamed answer, deltas tick this over
            // continuously, so it fires on dead connections, not slow models.
            // max_duration stays the published wall-clock bound.
            'timeout' => $connection->timeouts->firstByteSeconds,
            'max_duration' => $connection->timeouts->wallClockSeconds,
            'max_redirects' => 0,
            // Capped on the wire, the one size-cap mechanism this codebase has
            // (ConcurrentFeedFetcher::send(), HtmlPageFetcher, CatalogFaviconFetcher):
            // a provider answering with gigabytes is refused as the bytes arrive,
            // rather than truncated into a body that can only fail to parse. The
            // transport re-reports the aborted download as its own failure, which
            // readBody() translates back into this domain's refusal.
            'on_progress' => static function (int $downloaded): void {
                if ($downloaded > self::MAXIMUM_WIRE_BYTES) {
                    throw new ProviderUnreachableException(sprintf(
                        'That provider streamed more than %d bytes.',
                        self::MAXIMUM_WIRE_BYTES,
                    ));
                }
            },
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function completionPayload(CompletionRequest $request): array
    {
        $payload = [
            'model' => $request->model,
            'messages' => $request->messages,
            // A strict json_schema, not the older json_object: current LM
            // Studio rejects json_object with a 400, and grammar-constrained
            // decoding also keeps a weak local model's answer parseable
            // (#329). The name and schema ride on the request, set by the
            // phase that built the prompt.
            'response_format' => [
                'type' => 'json_schema',
                'json_schema' => [
                    'name' => $request->responseSchema->name,
                    'strict' => true,
                    'schema' => $request->responseSchema->schema,
                ],
            ],
            'stream' => true,
            // Ask for the usage message the stream would otherwise not carry
            // (#409). Unconditional because this is OpenAI spec, not a vendor
            // extension — unlike `reasoning` below, which is per-connection
            // for exactly that reason. OpenRouter documents it as inert; a
            // plain OpenAI-compatible endpoint sends no usage without it.
            'stream_options' => ['include_usage' => true],
            // The only guard here that prevents spend rather than
            // discarding what was already billed: the byte caps and the
            // timeouts all fire after the provider has generated the
            // tokens. Sized by the caller from the same reserve the
            // prompt left room for, so it can never truncate a reply the
            // prompt legitimately asked for.
            'max_tokens' => $request->maxAnswerTokens,
        ];

        if ($request->suppressReasoning) {
            // OpenRouter's reasoning extension: fully disables the thinking
            // phase, which ranking never needs (#323). An endpoint that does
            // not know the field ignores an unknown top-level member; a strict
            // one is why the flag is per-config rather than always on.
            $payload['reasoning'] = ['effort' => 'none'];
        }

        return $payload;
    }
}
