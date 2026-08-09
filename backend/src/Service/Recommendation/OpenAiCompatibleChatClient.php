<?php

declare(strict_types=1);

namespace App\Service\Recommendation;

use App\Service\Ai\Exception\CredentialsRejectedException;
use App\Service\Ai\Exception\ProviderUnreachableException;
use App\Service\Ai\ProviderCredentials;
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
        // One reader per call: it is this call's parsing state, the same way a
        // RecordedCall is this call's recording state.
        $reader = new CompletionStreamReader($this->decoder);

        $this->readInto($reader, $credentials, $request, $observer);

        // Prefer the answer channel; fall back to the reasoning channel only
        // when it is empty. LM Studio routes some models' whole answer through
        // reasoning_content and never fills content (#323), and the answer is
        // recoverable from there — the parser still validates whatever comes
        // back, so a reply that is only thinking is rejected downstream.
        $content = $reader->assistantContent() ?? $reader->reasoningContent();

        if (null === $content) {
            throw new ProviderUnreachableException('That provider answered without a completion.');
        }

        return $content;
    }

    private function readInto(
        CompletionStreamReader $reader,
        ProviderCredentials $credentials,
        CompletionRequest $request,
        CompletionStreamObserver $observer,
    ): void {
        try {
            $response = $this->request($credentials, $request);
            $status = $response->getStatusCode();

            if (401 === $status || 403 === $status) {
                throw new CredentialsRejectedException('That provider refused the API key.');
            }

            if ($status >= 300) {
                throw new ProviderUnreachableException(sprintf('That provider answered with status %d.', $status));
            }

            $this->readStream($response, $reader, $observer);
        } catch (ExceptionInterface $e) {
            throw new ProviderUnreachableException('That address did not answer.', 0, $e);
        }
    }

    /**
     * Feeds the response to the reader chunk by chunk. Passing the timeout to
     * stream() makes a stall arrive as a timeout chunk instead of an
     * exception, so it can carry its own message: the distinction between
     * "never answered" and "went silent mid-answer" is real to a user
     * deciding whether their provider is down or their network dropped.
     *
     * @throws ExceptionInterface
     */
    private function readStream(
        ResponseInterface $response,
        CompletionStreamReader $reader,
        CompletionStreamObserver $observer,
    ): void {
        foreach ($this->httpClient->stream($response, self::INACTIVITY_TIMEOUT_SECONDS) as $chunk) {
            // isTimeout() first — on a timeout chunk the other accessors
            // throw; same ordering hazard ConcurrentFeedFetcher documents. The
            // other direction matters too: on a non-timeout error chunk
            // isTimeout() itself throws, and that is how max_duration
            // exhaustion leaves here as the generic "did not answer".
            if ($chunk->isTimeout()) {
                $response->cancel();

                // Shape-neutral on purpose: the provider may have gone silent
                // mid-answer or never have started, and this bound covers both.
                // %s over the float renders "30" today and keeps the number
                // tied to the constant even if it ever became fractional.
                throw new ProviderUnreachableException(sprintf(
                    'That provider sent nothing for more than %s seconds.',
                    self::INACTIVITY_TIMEOUT_SECONDS,
                ));
            }

            // Symfony's own stream() protocol includes content-free framing
            // chunks (an isLast marker chunk in particular) alongside the real
            // SSE data — appending their empty content is harmless, but
            // reporting them to the observer would mean "the body grew" for a
            // call that added nothing.
            $content = $chunk->getContent();
            if ('' === $content) {
                continue;
            }

            $reader->consume($content);
            $this->guardRetainedSize($reader);
            $observer->streamProgressed(new CompletionStreamProgress(
                $reader->assistantContent() ?? '',
                $reader->wireBytes(),
                $reader->finishReason(),
            ));
        }
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
            'json' => [
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
            ],
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
}
