<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Llm\Completion\ChatCompletionClient;

use App\Service\Ai\Exception\CredentialsRejectedException;
use App\Service\Ai\Exception\ProviderReplyFailureExceptionInterface;
use App\Service\Ai\Exception\ProviderRunawayException;
use App\Service\Ai\Exception\ProviderUnreachableException;
use App\Service\Ai\Exception\RetryableProviderException;
use App\Service\Ai\Model\ProviderConnectionModel;
use App\Service\Ai\Support\RetryAfter;
use App\Service\Recommendation\Llm\Completion\CompletionBodyDecoder;
use App\Service\Recommendation\Llm\Completion\CompletionStreamObserver\CompletionStreamObserverInterface;
use App\Service\Recommendation\Llm\Completion\Model\CompletionOutcomeModel;
use App\Service\Recommendation\Llm\Completion\Model\CompletionRequestModel;
use App\Service\Recommendation\Llm\Completion\Model\Reasoning;
use App\Service\Recommendation\Llm\Completion\Pass\CompletionCallSlot;
use App\Service\Recommendation\Llm\Completion\Pass\CompletionStreamReader;
use App\Service\Recommendation\Llm\Completion\Pass\ConcurrentCompletion;
use App\Service\Recommendation\Llm\Completion\Pass\ErrorBody;
use App\Service\Recommendation\Run\Model\CallProgressModel;
use App\Service\Recommendation\Run\ProviderCallHeartbeat\ProviderCallHeartbeatInterface;
use App\Service\Recommendation\Support\ProviderErrorReason;
use Symfony\Contracts\HttpClient\ChunkInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Sends `POST {baseUrl}/chat/completions`. The caps are no SSRF boundary (docs/security.md#ai-provider-endpoints);
 * they stop one hostile or broken endpoint holding a request open or filling memory.
 */
final readonly class OpenAiCompatibleChatClient implements ChatCompletionClientInterface
{
    /**
     * Retained answer bytes one requested token buys, bounding a provider that ignores `max_tokens`. Twice the prompt
     * builder's estimate, so no legitimate reply trips it: the largest batch on record kept 12 KB of a 36 KB bound.
     */
    private const int RETAINED_BYTES_PER_REQUESTED_TOKEN = 8;

    /**
     * How much of a spoiled reply travels on with the failure. Comfortably
     * above what a retry quotes back, so the clip here never decides what the
     * prompt shows, and far below what a runaway retains.
     */
    private const int QUOTABLE_ANSWER_CHARS = 4000;

    /**
     * The flat memory bound on everything the reader holds, which bounds the blocking shape (no answer until its body
     * parses). It must clear reasoning: one #320 call legitimately buffered 1.9 MB before answering.
     */
    private const int MAXIMUM_RETAINED_BYTES = 2_097_152;

    // A runaway guard on what the provider may send, not a memory bound: the reader discards each decoded event.
    private const int MAXIMUM_WIRE_BYTES = 67_108_864;


    public function __construct(
        private HttpClientInterface $httpClient,
        private CompletionBodyDecoder $decoder,
        private ProviderCallHeartbeatInterface $heartbeat,
        private string $userAgent,
    ) {
    }

    public function complete(
        ProviderConnectionModel $connection,
        CompletionRequestModel $request,
        CompletionStreamObserverInterface $observer,
    ): string {
        // A one-call completeMany() wave, so the two never drift on reading, status guarding or answer recovery.
        $outcome = $this->completeMany($connection, [new ConcurrentCompletion($request, $observer)])[0];
        if ($outcome->isFailure()) {
            throw $outcome->cause();
        }

        return $outcome->content();
    }

    public function completeMany(ProviderConnectionModel $connection, array $calls): array
    {
        // $context maps each response to its call's slot, so outcomes stay aligned however the streams interleave;
        // fireRequests() already settled the calls that never got a response.
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
     * Fires every request up front (Symfony starts a transfer on request(), which makes the reads concurrent). A
     * request() failure such as a refused connection becomes that call's outcome and never stops its siblings.
     *
     * @param non-empty-list<ConcurrentCompletion> $calls
     *
     * @return array{0: \SplObjectStorage<ResponseInterface, CompletionCallSlot>, 1: list<?CompletionOutcomeModel>}
     */
    private function fireRequests(ProviderConnectionModel $connection, array $calls): array
    {
        /** @var \SplObjectStorage<ResponseInterface, CompletionCallSlot> $context */
        $context = new \SplObjectStorage();
        /** @var list<?CompletionOutcomeModel> $outcomes */
        $outcomes = array_fill(0, \count($calls), null);

        foreach ($calls as $index => $call) {
            try {
                $response = $this->request($connection, $call->request);
            } catch (ExceptionInterface $exception) {
                $outcomes[$index] = CompletionOutcomeModel::failure(
                    ProviderUnreachableException::didNotAnswer($exception),
                );

                continue;
            }

            $context[$response] = new CompletionCallSlot(
                $index,
                new CompletionStreamReader($this->decoder),
                $call->observer,
                $connection,
                $call->request->maxAnswerTokens,
                new ErrorBody(),
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
     * Reads one chunk of one call's stream and settles the call when it ends; null means still in progress. A
     * transport failure becomes that call's outcome, never an exception that aborts its siblings' reads.
     */
    private function advance(
        ResponseInterface $response,
        ChunkInterface $chunk,
        CompletionCallSlot $slot,
    ): ?CompletionOutcomeModel {
        try {
            if (!$this->consumeChunk($response, $chunk, $slot)) {
                return null;
            }

            return CompletionOutcomeModel::answer($this->contentOf($slot->reader));
        } catch (ProviderReplyFailureExceptionInterface $spoiledReply) {
            $response->cancel();

            return CompletionOutcomeModel::unusableReply($spoiledReply);
        } catch (CredentialsRejectedException | ProviderUnreachableException | RetryableProviderException $failure) {
            $response->cancel();

            return CompletionOutcomeModel::failure($failure);
        } catch (ExceptionInterface $transportFailure) {
            $response->cancel();

            return CompletionOutcomeModel::failure($this->transportFailureOf($slot, $transportFailure));
        }
    }

    /**
     * A transport cut after the provider reported `length` (its whole `max_tokens` spent) is a runaway. Anything else
     * stays unreachable: a reset mid-answer is a dead connection, whatever the byte count.
     */
    private function transportFailureOf(CompletionCallSlot $slot, ExceptionInterface $failure): \RuntimeException
    {
        if ($slot->errorBody->isOpen()) {
            return $slot->errorBody->failure();
        }

        if (!$slot->reader->hitTokenCeiling()) {
            return ProviderUnreachableException::didNotAnswer($failure);
        }

        return new ProviderRunawayException(
            sprintf(
                'That provider spent its whole %d-token ceiling and did not stop, %d bytes in.',
                $slot->maximumAnswerTokens,
                $slot->reader->wireBytes(),
            ),
            $this->quotableAnswerOf($slot),
        );
    }

    /**
     * A response that never yields a closing chunk (it should always) still
     * owes the caller one outcome per call, so an unsettled slot becomes the
     * same answerless failure an empty completion does.
     *
     * @param list<?CompletionOutcomeModel> $outcomes
     *
     * @return list<CompletionOutcomeModel>
     */
    private function settleOutstanding(array $outcomes): array
    {
        return array_map(
            static fn (?CompletionOutcomeModel $outcome): CompletionOutcomeModel
                => $outcome ?? CompletionOutcomeModel::failure(
                    new ProviderUnreachableException('That provider answered without a completion.'),
                ),
            $outcomes,
        );
    }

    /**
     * The per-chunk core shared by the single-call and concurrent reads. Feeds one chunk to the reader and reports it
     * to the observer, or to the error body once the status says the call failed; returns true once the answer is
     * complete.
     *
     * @throws CredentialsRejectedException
     * @throws ProviderUnreachableException
     * @throws RetryableProviderException
     * @throws ExceptionInterface
     */
    private function consumeChunk(ResponseInterface $response, ChunkInterface $chunk, CompletionCallSlot $slot): bool
    {
        // isTimeout() first: on a timeout chunk the other accessors throw, and on an error chunk isTimeout() throws
        // itself, which is how max_duration exhaustion leaves as the generic "did not answer".
        if ($chunk->isTimeout()) {
            $response->cancel();

            if ($slot->errorBody->isOpen()) {
                throw $slot->errorBody->failure();
            }

            // Shape-neutral: the provider may have gone silent mid-answer or never started.
            throw new ProviderUnreachableException(sprintf(
                'That provider sent nothing for more than %s seconds.',
                $slot->connection->timeouts->firstByteSeconds,
            ));
        }

        // Headers have arrived: the status is readable here without blocking,
        // which is also the only point the concurrent read can inspect it.
        if ($chunk->isFirst()) {
            $this->guardStatus($response, $slot->errorBody);
        }

        $content = $chunk->getContent();
        if ($slot->errorBody->isOpen()) {
            return $this->collectErrorBody($slot, $content, $chunk->isLast());
        }

        $this->consumeAnswer($slot, $content);

        return $chunk->isLast();
    }

    /** stream() also yields empty framing chunks; reporting one would tell the observer the body grew. */
    private function consumeAnswer(CompletionCallSlot $slot, string $content): void
    {
        if ('' === $content) {
            return;
        }

        $reader = $slot->reader;
        $reader->consume($content);
        $this->guardRetainedSize($slot);
        $slot->observer->streamProgressed(new CallProgressModel(
            $reader->assistantContent() ?? '',
            $reader->wireBytes(),
            $reader->finishReason(),
            $reader->usage(),
        ));
    }

    private function guardStatus(ResponseInterface $response, ErrorBody $errorBody): void
    {
        $status = $response->getStatusCode();

        if (401 === $status || 403 === $status) {
            throw CredentialsRejectedException::refusedKey();
        }

        if (\in_array($status, [429, 502, 503, 504], true)) {
            throw new RetryableProviderException($status, RetryAfter::secondsIn($response));
        }

        if ($status >= 300) {
            $errorBody->open($status);
        }
    }

    private function collectErrorBody(CompletionCallSlot $slot, string $content, bool $isLast): bool
    {
        $slot->errorBody->collect($content);
        if (!$isLast) {
            return false;
        }

        throw $slot->errorBody->failure(
            ProviderErrorReason::in($slot->errorBody->text(), $slot->connection->credentials),
        );
    }

    /**
     * Prefers `content`, falling back to the reasoning channel, where LM Studio puts some models' whole answer. The
     * caller's parser still rejects a reply that is only thinking.
     */
    private function contentOf(CompletionStreamReader $reader): string
    {
        $content = $reader->assistantContent() ?? $reader->reasoningContent();

        if (null === $content) {
            throw new ProviderUnreachableException('That provider answered without a completion.');
        }

        return $content;
    }

    /**
     * The answer bound follows what this call asked for and catches a model that will not stop; the flat retained
     * bound catches a body that will not fit in memory, such as a blocking shape's buffered reasoning.
     */
    private function guardRetainedSize(CompletionCallSlot $slot): void
    {
        $maximumAnswerBytes = $slot->maximumAnswerTokens * self::RETAINED_BYTES_PER_REQUESTED_TOKEN;

        if ($slot->reader->answerBytes() > $maximumAnswerBytes) {
            throw new ProviderRunawayException(
                sprintf('That provider answered with more than %d bytes.', $maximumAnswerBytes),
                $this->quotableAnswerOf($slot),
            );
        }

        if ($slot->reader->retainedBytes() > self::MAXIMUM_RETAINED_BYTES) {
            throw new ProviderRunawayException(
                sprintf('That provider sent more than %d bytes without completing.', self::MAXIMUM_RETAINED_BYTES),
                $this->quotableAnswerOf($slot),
            );
        }
    }

    /** As much of a spoiled reply as a retry can quote, clipped here so the full runaway travels no further. */
    private function quotableAnswerOf(CompletionCallSlot $slot): string
    {
        return mb_substr($slot->reader->assistantContent() ?? '', 0, self::QUOTABLE_ANSWER_CHARS);
    }

    private function request(ProviderConnectionModel $connection, CompletionRequestModel $request): ResponseInterface
    {
        return $this->httpClient->request('POST', $connection->credentials->baseUrl . '/chat/completions', [
            'headers' => [
                'Accept' => 'text/event-stream, application/json',
                // No transparent compression, so the wire cap below also bounds the decompressed body.
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
            // Refused on the wire as the bytes arrive; advance() turns the aborted transfer into this call's failure.
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
    private function completionPayload(CompletionRequestModel $request): array
    {
        $payload = [
            'model' => $request->model,
            'messages' => $request->messages,
            // Strict json_schema, not json_object: LM Studio rejects json_object with a 400, and constrained decoding
            // keeps a weak local model's answer parseable.
            'response_format' => [
                'type' => 'json_schema',
                'json_schema' => [
                    'name' => $request->responseSchema->name,
                    'strict' => true,
                    'schema' => $request->responseSchema->schema,
                ],
            ],
            'stream' => true,
            // OpenAI spec, so unconditional: without it a plain OpenAI-compatible endpoint streams no usage report.
            'stream_options' => ['include_usage' => true],
            // The only guard that prevents spend rather than discarding billed tokens; the caller sizes it from the
            // reserve the prompt left, so it never truncates a reply the prompt asked for.
            'max_tokens' => $request->maxAnswerTokens,
        ];

        if (Reasoning::Suppressed === $request->reasoning) {
            // OpenRouter's reasoning extension; an endpoint that does not know the field ignores it.
            $payload['reasoning'] = ['effort' => 'none'];
        }

        return $payload;
    }
}
