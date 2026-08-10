<?php

declare(strict_types=1);

namespace App\Service\Recommendation;

use App\Service\Ai\Exception\CredentialsRejectedException;
use App\Service\Ai\Exception\ProviderUnreachableException;
use App\Service\Ai\ProviderCredentials;
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
 *
 * @phpstan-type ResponseSlot array{index: int, reader: CompletionStreamReader, observer: CompletionStreamObserver}
 */
final readonly class OpenAiCompatibleChatClient implements ChatCompletionClient
{
    // A ranking over a large batch can legitimately generate for minutes, and
    // a reasoning model spends most of that thinking before it answers at all:
    // 120 s failed real batches three times running and killed the run (#320),
    // and 300 s still failed a slow local model on a large batch with the
    // generic "did not answer" once the whole generation overran the wall
    // clock. 600 s carries a local run that streams for many minutes.
    //
    // Public because it is a published bound, not an implementation detail:
    // WorkerPresence::FRESH_SECONDS has to outlast one call of this length or
    // the worker looks dead while it is merely thinking (#311).
    //
    // This is the ceiling under a worker, which owns its own process. In the
    // poll regime the tick is a web request, so the real ceiling there is
    // whatever the web server allows a FastCGI request to run — this constant
    // cannot raise that, and on a short-window host the call dies first.
    public const float TIMEOUT_SECONDS = 600.0;

    // The answer arrives as an SSE stream (#312), so silence — no delta for
    // this long — means a dead connection, not a thinking model. The binding
    // constraint on this value is time-to-first-token: the provider sends
    // nothing while it evaluates the prompt, and a local model on a large
    // #308 batch needs the headroom. Raise it before inventing a second
    // "first token" timeout.
    //
    // Note what this bound really covers: Symfony's idle timeout also bounds
    // the wait for the response headers, so the clock runs from the request
    // going out, not from the first body chunk. It is time-to-first-*byte*.
    // A provider that ignores `stream: true` sends nothing at all — headers
    // included — until the whole answer is ready, so it now has this window
    // rather than the old TIMEOUT_SECONDS to answer end to end. That is the
    // accepted price of failing a dead connection in 180 s instead of 300 s.
    //
    // Raised from 30 s: a local model evaluating a large #308 batch on modest
    // hardware legitimately spends over a minute before the first token, and
    // 30 s failed those runs as "sent nothing" while the model was still
    // thinking. 180 s stays clear of the 300 s wall clock.
    private const float INACTIVITY_TIMEOUT_SECONDS = 180.0;

    // What the reader holds in memory: the answer, plus the envelope for a
    // provider that ignores `stream: true`. Generous against real answers,
    // which run to a few kilobytes.
    private const int MAXIMUM_ANSWER_BYTES = 2_097_152;

    // What the provider is allowed to send. This is a runaway guard, not a
    // memory bound — the reader discards each event once decoded, so wire
    // bytes no longer accumulate. It has to clear reasoning: a single #320
    // call legitimately spent 1.9 MB of stream before answering.
    private const int MAXIMUM_WIRE_BYTES = 67_108_864;


    public function __construct(
        private HttpClientInterface $httpClient,
        private CompletionBodyDecoder $decoder,
        private string $userAgent,
    ) {
    }

    public function complete(
        ProviderCredentials $credentials,
        CompletionRequest $request,
        CompletionStreamObserver $observer,
    ): string {
        // A single-call wave through the same concurrent path (#344): one
        // call is just completeMany() with a call list of one, so the two
        // never drift on how a chunk is read, a status is guarded, or an
        // answer is recovered.
        $outcome = $this->completeMany($credentials, [new ConcurrentCompletion($request, $observer)])[0];
        if ($outcome->isFailure()) {
            throw $outcome->cause();
        }

        return $outcome->content();
    }

    public function completeMany(ProviderCredentials $credentials, array $calls): array
    {
        // Each response carries its own reader (its parsing state) and its own
        // observer. SplObjectStorage maps the response object the stream() loop
        // yields back to those and to the $calls index, so the outcomes stay
        // aligned however the concurrent streams interleave. fireRequests()
        // already seeded $outcomes for any call that never made it to a
        // response at all, so a request-phase failure and a stream-phase one
        // both end up in the same per-call outcome shape.
        [$context, $outcomes] = $this->fireRequests($credentials, $calls);

        $responses = $this->httpClient->stream($this->responsesIn($context), self::INACTIVITY_TIMEOUT_SECONDS);
        foreach ($responses as $response => $chunk) {
            $slot = $context[$response];

            // A failed call cancels its response but the loop may still yield a
            // trailing chunk for it; a finished call is likewise done. Ignore
            // both — this call already has its outcome.
            if (null !== $outcomes[$slot['index']]) {
                continue;
            }

            $outcomes[$slot['index']] = $this->advance($response, $chunk, $slot);
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
     * @return array{0: \SplObjectStorage<ResponseInterface, ResponseSlot>, 1: list<?CompletionOutcome>}
     */
    private function fireRequests(ProviderCredentials $credentials, array $calls): array
    {
        /** @var \SplObjectStorage<ResponseInterface, ResponseSlot> $context */
        $context = new \SplObjectStorage();
        /** @var list<?CompletionOutcome> $outcomes */
        $outcomes = array_fill(0, \count($calls), null);

        foreach ($calls as $index => $call) {
            try {
                $response = $this->request($credentials, $call->request);
            } catch (ExceptionInterface $e) {
                $outcomes[$index] = CompletionOutcome::failure(
                    new ProviderUnreachableException('That address did not answer.', 0, $e),
                );

                continue;
            }

            $context[$response] = [
                'index' => $index,
                'reader' => new CompletionStreamReader($this->decoder),
                'observer' => $call->observer,
            ];
        }

        return [$context, array_values($outcomes)];
    }

    /**
     * @param \SplObjectStorage<ResponseInterface, ResponseSlot> $context
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
     *
     * @param ResponseSlot $slot
     */
    private function advance(ResponseInterface $response, ChunkInterface $chunk, array $slot): ?CompletionOutcome
    {
        try {
            if (!$this->consumeChunk($response, $chunk, $slot['reader'], $slot['observer'])) {
                return null;
            }

            return CompletionOutcome::answer($this->contentOf($slot['reader']));
        } catch (CredentialsRejectedException | ProviderUnreachableException $failure) {
            $response->cancel();

            return CompletionOutcome::failure($failure);
        } catch (ExceptionInterface $transportFailure) {
            $response->cancel();

            return CompletionOutcome::failure(
                new ProviderUnreachableException('That address did not answer.', 0, $transportFailure),
            );
        }
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
    private function consumeChunk(
        ResponseInterface $response,
        ChunkInterface $chunk,
        CompletionStreamReader $reader,
        CompletionStreamObserver $observer,
    ): bool {
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
                self::INACTIVITY_TIMEOUT_SECONDS,
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
            $this->guardRetainedSize($reader);
            $observer->streamProgressed(new CompletionStreamProgress(
                $reader->assistantContent() ?? '',
                $reader->wireBytes(),
                $reader->finishReason(),
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

    private function guardRetainedSize(CompletionStreamReader $reader): void
    {
        if ($reader->retainedBytes() <= self::MAXIMUM_ANSWER_BYTES) {
            return;
        }

        throw new ProviderUnreachableException(sprintf(
            'That provider answered with more than %d bytes.',
            self::MAXIMUM_ANSWER_BYTES,
        ));
    }

    private function request(ProviderCredentials $credentials, CompletionRequest $request): ResponseInterface
    {
        return $this->httpClient->request('POST', $credentials->baseUrl . '/chat/completions', [
            'headers' => [
                'Authorization' => 'Bearer ' . $credentials->apiKey,
                'Accept' => 'text/event-stream, application/json',
                // Refuse transparent compression so the wire cap below also bounds
                // the buffered body — gzip would otherwise let a small reply
                // decompress unbounded after the cap has already passed it. Same
                // reasoning as ConcurrentFeedFetcher::headers().
                'Accept-Encoding' => 'identity',
                'User-Agent' => $this->userAgent,
            ],
            'json' => $this->completionPayload($request),
            // Idle bound only: with a streamed answer, deltas tick this over
            // continuously, so it fires on dead connections, not slow models.
            // max_duration stays the published wall-clock bound.
            'timeout' => self::INACTIVITY_TIMEOUT_SECONDS,
            'max_duration' => self::TIMEOUT_SECONDS,
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
