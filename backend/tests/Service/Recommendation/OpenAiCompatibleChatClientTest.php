<?php

declare(strict_types=1);

namespace App\Tests\Service\Recommendation;

use App\Service\Ai\Exception\CredentialsRejectedException;
use App\Service\Ai\Exception\ProviderRunawayException;
use App\Service\Ai\Exception\ProviderUnreachableException;
use App\Service\Ai\ProviderConnection;
use App\Service\Ai\ProviderCredentials;
use App\Service\Ai\ProviderTimeouts;
use App\Service\Recommendation\CompletionBodyDecoder;
use App\Service\Recommendation\CompletionRequest;
use App\Service\Recommendation\CompletionStreamHeartbeat;
use App\Service\Recommendation\CompletionStreamProgress;
use App\Service\Recommendation\CompletionStreamObserver;
use App\Service\Recommendation\ConcurrentCompletion;
use App\Service\Recommendation\JsonSchema;
use App\Service\Recommendation\NullCompletionStreamObserver;
use App\Service\Recommendation\OpenAiCompatibleChatClient;
use App\Tests\Support\CountingCompletionStreamHeartbeat;
use App\Tests\Support\NullCompletionStreamHeartbeat;
use App\Tests\Support\ResponseCapturingHttpClient;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class OpenAiCompatibleChatClientTest extends TestCase
{
    private function credentials(): ProviderCredentials
    {
        return ProviderCredentials::fromStoredConfiguration('https://api.example.test/v1', 'sk-test');
    }

    private function connection(): ProviderConnection
    {
        return new ProviderConnection($this->credentials(), ProviderTimeouts::standard());
    }

    /** @return list<array{role: string, content: string}> */
    private function messages(): array
    {
        return [['role' => 'user', 'content' => 'Rank these entries.']];
    }

    private function request(): CompletionRequest
    {
        return new CompletionRequest('m', $this->messages(), 2048, $this->schema(), false);
    }

    private function suppressingRequest(): CompletionRequest
    {
        return new CompletionRequest('m', $this->messages(), 2048, $this->schema(), true);
    }

    private function schema(): JsonSchema
    {
        return new JsonSchema('test_schema', ['type' => 'object']);
    }

    private function clientUsing(HttpClientInterface $httpClient): OpenAiCompatibleChatClient
    {
        return $this->clientReportingTo($httpClient, new NullCompletionStreamHeartbeat());
    }

    private function clientReportingTo(
        HttpClientInterface $httpClient,
        CompletionStreamHeartbeat $heartbeat,
    ): OpenAiCompatibleChatClient {
        return new OpenAiCompatibleChatClient(
            $httpClient,
            new CompletionBodyDecoder(),
            $heartbeat,
            'SimpleFeedReader/1.0',
        );
    }

    private function clientAnswering(MockResponse $response): OpenAiCompatibleChatClient
    {
        return $this->clientUsing(new MockHttpClient($response));
    }

    /** @param list<MockResponse> $responses one per concurrent call, in call order */
    private function clientReturning(array $responses): OpenAiCompatibleChatClient
    {
        return $this->clientUsing(new MockHttpClient($responses));
    }

    /** A minimal SSE stream whose single delta carries $answer as the assistant content. */
    private function sseStream(string $answer): MockResponse
    {
        $event = 'data: ' . json_encode(
            ['choices' => [['delta' => ['content' => $answer]]]],
            \JSON_THROW_ON_ERROR,
        ) . "\n\n";

        return new MockResponse([$event, "data: [DONE]\n\n"]);
    }

    private function concurrentCall(CompletionStreamObserver $observer): ConcurrentCompletion
    {
        return new ConcurrentCompletion($this->request(), $observer);
    }

    public function testReturnsTheAssistantContentJoinedFromTheStream(): void
    {
        $seen = [];
        $client = new MockHttpClient(function (string $method, string $url, array $options) use (&$seen): MockResponse {
            $seen = [
                'method' => $method,
                'url' => $url,
                'headers' => $options['headers'] ?? [],
                'body' => $options['body'] ?? null,
                'timeout' => $options['timeout'] ?? null,
                'max_duration' => $options['max_duration'] ?? null,
            ];

            return new MockResponse([
                'data: {"choices":[{"delta":{"role":"assistant"}}]}' . "\n\n",
                'data: {"choices":[{"delta":{"content":"{\"recommend"}}]}' . "\n\n",
                'data: {"choices":[{"delta":{"content":"ations\":[]}"}}]}' . "\n\n",
                'data: [DONE]' . "\n\n",
            ]);
        });

        $content = $this->clientUsing($client)
            ->complete($this->connection(), $this->request(), new NullCompletionStreamObserver());

        self::assertSame('{"recommendations":[]}', $content);

        /**
         * @var array{
         *     method: string,
         *     url: string,
         *     headers: array<int, string>,
         *     body: string,
         *     timeout: float|null,
         *     max_duration: float|null,
         * } $seen
         */
        self::assertSame('POST', $seen['method']);
        self::assertSame('https://api.example.test/v1/chat/completions', $seen['url']);
        self::assertContains('Authorization: Bearer sk-test', $seen['headers']);
        self::assertContains('Accept: text/event-stream, application/json', $seen['headers']);
        self::assertContains('Accept-Encoding: identity', $seen['headers']);

        // The idle bound is what makes a dead connection fail in 180 s rather
        // than at the full wall clock — the whole point of #312. The wall bound
        // is the published 600 s budget WorkerPresence::FRESH_SECONDS is sized
        // against (#311). Pinning the numbers, not the constants, means a
        // regression that swaps one for the other or drops max_duration
        // shows up here instead of only in production behaviour.
        self::assertSame(180.0, $seen['timeout']);
        self::assertSame(600.0, $seen['max_duration']);

        // Asserted as a whole body rather than key by key: `max_tokens` is the
        // only guard here that stops tokens being generated instead of
        // discarding them once billed, so it must not be droppable without a
        // red test. Dropping it once let a looping model bill 4.4 million
        // characters against a reply that runs to a few kilobytes. The value
        // is the caller's, not a constant of this class — a client that
        // substituted its own would silently truncate the large batches the
        // packer deliberately reserved room for.
        $decodedBody = json_decode($seen['body'], true);
        self::assertSame([
            'model' => 'm',
            'messages' => $this->messages(),
            // Structured output rides as a json_schema built from the request's
            // own schema (OpenAiCompatibleChatClient records why, #329).
            'response_format' => [
                'type' => 'json_schema',
                'json_schema' => [
                    'name' => 'test_schema',
                    'strict' => true,
                    'schema' => ['type' => 'object'],
                ],
            ],
            'stream' => true,
            // Asks the provider to include its usage report in the stream
            // (#409) — see OpenAiCompatibleChatClient::completionPayload()
            // for why this is unconditional.
            'stream_options' => ['include_usage' => true],
            'max_tokens' => 2048,
        ], $decodedBody);
    }

    /**
     * The transport is the only place that knows a chunk arrived, so it is
     * what tells a worker's liveness that the process reading the stream is
     * still running (#433). Without this ping, a worker inside a long call
     * would age out of WorkerPresence's freshness window and the poll driver
     * would stop deferring to it while it was working.
     */
    public function testItPingsTheHeartbeatAsTheAnswerStreams(): void
    {
        $heartbeat = new CountingCompletionStreamHeartbeat();

        $this->clientReportingTo(
            new MockHttpClient(new MockResponse([
                'data: {"choices":[{"delta":{"content":"{}"}}]}' . "\n\n",
                'data: [DONE]' . "\n\n",
            ])),
            $heartbeat,
        )->complete($this->connection(), $this->request(), new NullCompletionStreamObserver());

        self::assertGreaterThan(0, $heartbeat->beats());
    }

    /**
     * The bounds the request is sent under come from the connection, not from
     * this class (#433). Pinned for both profiles, because a wiring that read
     * one fixed profile would pass a standard-connection test and silently
     * keep failing the slow local model this setting exists for.
     *
     * `timeout` is the idle bound and `max_duration` the wall clock, the two
     * option names Symfony's transport gives them.
     *
     * @param array<string, mixed> $expected
     */
    #[DataProvider('profileOptions')]
    public function testTheRequestCarriesTheConnectionsOwnBounds(
        ProviderTimeouts $timeouts,
        array $expected,
    ): void {
        $seen = [];
        $client = new MockHttpClient(function (string $method, string $url, array $options) use (&$seen): MockResponse {
            $seen = $options;

            return new MockResponse([
                'data: {"choices":[{"delta":{"content":"{}"}}]}' . "\n\n",
                'data: [DONE]' . "\n\n",
            ]);
        });

        $this->clientUsing($client)->complete(
            new ProviderConnection($this->credentials(), $timeouts),
            $this->request(),
            new NullCompletionStreamObserver(),
        );

        self::assertSame($expected['timeout'], $seen['timeout']);
        self::assertSame($expected['max_duration'], $seen['max_duration']);
    }

    /**
     * @return iterable<string, array{ProviderTimeouts, array<string, mixed>}>
     */
    public static function profileOptions(): iterable
    {
        yield 'standard' => [
            ProviderTimeouts::standard(),
            ['timeout' => 180.0, 'max_duration' => 600.0],
        ];
        yield 'slow model' => [
            ProviderTimeouts::forSlowModel(),
            ['timeout' => 900.0, 'max_duration' => 3600.0],
        ];
    }

    /**
     * And the silence the reader refuses is the connection's own too: the
     * message a run's debug log records must name the bound that actually
     * fired, or a slow connection's failure reads as a standard one's.
     */
    public function testASilentProviderIsRefusedAgainstTheConnectionsOwnFirstByteBound(): void
    {
        $body = static function (): \Generator {
            yield 'data: {"choices":[{"delta":{"content":"par"}}]}' . "\n\n";
            yield '';
        };

        $this->expectException(ProviderUnreachableException::class);
        $this->expectExceptionMessage('That provider sent nothing for more than 900 seconds.');

        $this->clientUsing(new ResponseCapturingHttpClient(new MockResponse($body())))->complete(
            new ProviderConnection($this->credentials(), ProviderTimeouts::forSlowModel()),
            $this->request(),
            new NullCompletionStreamObserver(),
        );
    }

    /**
     * A keyless credential (a local model server) must not send `Bearer ` with
     * nothing after it — ProviderCredentials::authorizationHeaders() drops the
     * header entirely, and every other header this call sets must survive
     * that.
     */
    public function testAKeylessCredentialSendsNoAuthorizationHeader(): void
    {
        $seen = [];
        $client = new MockHttpClient(function (string $method, string $url, array $options) use (&$seen): MockResponse {
            $seen = ['headers' => $options['headers'] ?? []];

            return new MockResponse([
                'data: {"choices":[{"delta":{"content":"{}"}}]}' . "\n\n",
                'data: [DONE]' . "\n\n",
            ]);
        });

        $credentials = ProviderCredentials::fromStoredConfiguration('https://api.example.test/v1', '');
        $this->clientUsing($client)->complete(
            new ProviderConnection($credentials, ProviderTimeouts::standard()),
            $this->request(),
            new NullCompletionStreamObserver()
        );

        /** @var array{headers: array<int, string>} $seen */
        $authorizationHeaders = array_filter(
            $seen['headers'],
            static fn (string $header): bool => str_starts_with($header, 'Authorization:'),
        );
        self::assertSame([], $authorizationHeaders);
        self::assertContains('Accept: text/event-stream, application/json', $seen['headers']);
        self::assertContains('Accept-Encoding: identity', $seen['headers']);
    }

    /**
     * A provider that ignores `stream: true` answers with the blocking envelope;
     * the client must accept it exactly as it did before #312.
     *
     * Shape only, not timing: the MockResponse answers instantly, so this says
     * nothing about whether such a provider can still finish in time. It
     * cannot, past the profile's first-byte bound — that bound covers the wait
     * for the response headers too, and ProviderTimeouts records why the branch
     * accepts that.
     */
    public function testABlockingEnvelopeAnswerStillWorks(): void
    {
        $client = $this->clientAnswering(
            new MockResponse('{"choices":[{"message":{"content":"{\"recommendations\":[]}"}}]}'),
        );

        self::assertSame(
            '{"recommendations":[]}',
            $client->complete($this->connection(), $this->request(), new NullCompletionStreamObserver()),
        );
    }

    /**
     * The point of #312: a stream that goes silent is aborted after the
     * inactivity window and surfaces as the same typed transport failure the
     * #308 retry pipeline already handles — not after the full 600 s budget.
     *
     * MockHttpClient turns an empty string yielded by a body generator into a
     * timeout chunk, the documented way to simulate a stalled stream.
     */
    public function testASilentStreamIsAbortedAsUnreachable(): void
    {
        $body = static function (): \Generator {
            yield 'data: {"choices":[{"delta":{"content":"par"}}]}' . "\n\n";
            yield '';
        };
        $client = new ResponseCapturingHttpClient(new MockResponse($body()));

        // try/catch rather than expectException, unlike the rest of this file,
        // because the cancel assertion below has to run after the throw.
        try {
            $this->clientUsing($client)
                ->complete($this->connection(), $this->request(), new NullCompletionStreamObserver());
            self::fail(ProviderUnreachableException::class . ' was not thrown.');
        } catch (ProviderUnreachableException $e) {
            self::assertSame('That provider sent nothing for more than 180 seconds.', $e->getMessage());
        }

        // A stalled response is canceled rather than left open — leaving it
        // running would hold the connection past the exception that already
        // told the caller it is dead.
        self::assertTrue($client->lastResponse?->getInfo('canceled'));
    }

    public function testRejectedCredentialsBecomeTheTypedException(): void
    {
        $client = $this->clientAnswering(new MockResponse('{"error":"nope"}', ['http_code' => 401]));

        $this->expectException(CredentialsRejectedException::class);
        $this->expectExceptionMessage('That provider refused the API key.');
        $client->complete($this->connection(), $this->request(), new NullCompletionStreamObserver());
    }

    public function testNonJsonEnvelopeIsUnreachable(): void
    {
        $client = $this->clientAnswering(new MockResponse('not json'));

        $this->expectException(ProviderUnreachableException::class);
        $client->complete($this->connection(), $this->request(), new NullCompletionStreamObserver());
    }

    public function testEnvelopeWithoutContentIsUnreachable(): void
    {
        $client = $this->clientAnswering(new MockResponse('{"choices":[]}'));

        $this->expectException(ProviderUnreachableException::class);
        $client->complete($this->connection(), $this->request(), new NullCompletionStreamObserver());
    }

    /**
     * Pins the documented contract at the boundary: 300 itself, not just
     * something comfortably past it, is a refusal — and pins that the
     * provider's own status reaches the user's problem detail, rather than
     * being flattened into the generic "did not answer".
     *
     * The message assertion is what makes this a real kill, not just a type
     * check: if "$status >= 300" ever loosened to "$status > 300", 300 would
     * fall through to getContent(), which Symfony's HttpClientInterface
     * raises as RedirectionException for any unfollowed 3xx — caught by the
     * same `catch (ExceptionInterface $e)`, but rewritten to the generic
     * "That address did not answer." rather than this status-carrying one.
     * Same exception type either way; different message.
     */
    public function testAStatusOfExactly300IsAlsoUnreachable(): void
    {
        $client = $this->clientAnswering(new MockResponse(
            '{"choices":[{"message":{"content":"ok"}}]}',
            ['http_code' => 300],
        ));

        $this->expectException(ProviderUnreachableException::class);
        $this->expectExceptionMessage('That provider answered with status 300.');
        $client->complete($this->connection(), $this->request(), new NullCompletionStreamObserver());
    }

    /**
     * Pins the same contract as the 300 case, at a status a real provider
     * would plausibly send: its own outage reaches the user's problem detail
     * instead of being flattened into a generic "did not answer".
     *
     * The message assertion is what kills the `Throw_` mutant on this branch:
     * deleting the `throw` also falls through to getContent(), which raises
     * ServerException for a 5xx — caught and rewritten to the generic
     * message, not this status-carrying one. Same exception type, different
     * message.
     */
    public function testAServerErrorIsUnreachable(): void
    {
        $client = $this->clientAnswering(new MockResponse(
            '{"choices":[{"message":{"content":"ok"}}]}',
            ['http_code' => 500],
        ));

        $this->expectException(ProviderUnreachableException::class);
        $this->expectExceptionMessage('That provider answered with status 500.');
        $client->complete($this->connection(), $this->request(), new NullCompletionStreamObserver());
    }

    /**
     * Pins the documented contract: a redirect answer is a refusal, not
     * something to follow. `max_redirects: 0` is load-bearing hardening on
     * the one class in this branch that talks to an untrusted external
     * endpoint — a client that silently followed a redirect would hand the
     * API key to whatever host the provider's `Location` header names.
     *
     * This does NOT prove the `max_redirects: 0` request option specifically —
     * verified by direct experiment, not assumed. MockHttpClient never
     * performs real redirect-following regardless of that option's value: a
     * queued second response is consumed only by an explicit second
     * ->request() call, which nothing here makes. The identical limitation
     * already applies to OpenAiCompatibleCatalog's own `max_redirects: 0`,
     * whose Increment/DecrementInteger mutants escape for the same reason.
     * Proving the option's wire effect would need a real transport against a
     * server that actually redirects, which is an integration test, not this
     * unit suite's job.
     */
    public function testARedirectIsRefusedRatherThanFollowed(): void
    {
        $client = new MockHttpClient([
            new MockResponse('', ['http_code' => 302, 'response_headers' => ['location' => 'https://elsewhere.test/']]),
            new MockResponse('{"choices":[{"message":{"content":"should never be read"}}]}'),
        ]);

        $this->expectException(ProviderUnreachableException::class);
        $this->clientUsing($client)
            ->complete($this->connection(), $this->request(), new NullCompletionStreamObserver());
    }

    public function testAForbiddenAnswerIsAlsoARejectedKey(): void
    {
        $client = $this->clientAnswering(new MockResponse('{"error":"nope"}', ['http_code' => 403]));

        $this->expectException(CredentialsRejectedException::class);
        $this->expectExceptionMessage('That provider refused the API key.');
        $client->complete($this->connection(), $this->request(), new NullCompletionStreamObserver());
    }

    /**
     * This is otherwise-valid JSON that would decode into one perfectly good
     * completion — the content is just padded well past MAXIMUM_RESPONSE_BYTES.
     * The wire cap aborting the download is the ONLY reason this throws: without
     * it, this body parses and complete() returns successfully.
     *
     * Split into small chunks: MockHttpClient delivers one plain string as a
     * single chunk, and reports progress only once it is already complete.
     * Chunking matches how a real response actually streams and is what makes
     * this test able to catch a cap that stopped firing.
     */
    public function testAnOversizedAnswerIsRefusedAsARunaway(): void
    {
        $body = '{"choices":[{"message":{"content":"' . str_repeat('a', 2_100_000) . '"}}]}';
        $client = $this->clientAnswering(new MockResponse(str_split($body, 50_000)));

        $this->expectException(ProviderRunawayException::class);
        $client->complete($this->connection(), $this->request(), new NullCompletionStreamObserver());
    }

    /**
     * complete() is a one-call completeMany() wave (#344): this pins that the
     * delegation still preserves complete()'s original contract even for a
     * failure that never produced a response at all (the request() call
     * itself refuses the connection) -- completeMany() settles it as that
     * call's own outcome (testCompleteManySettlesARequestPhaseFailureAsThatCallsOutcome
     * below pins that half directly), and complete() unwraps a single failed
     * outcome back into a thrown exception.
     */
    public function testTransportErrorsAreUnreachable(): void
    {
        $client = new MockHttpClient(static function (): MockResponse {
            throw new TransportException('Connection refused');
        });

        // The exact message, not merely the exception class: without the
        // throw in the ExceptionInterface catch, contentOf() would still
        // raise a ProviderUnreachableException of its own (an empty reader
        // reads as "answered without a completion"), so only the message
        // proves this specific catch block actually ran.
        try {
            $this->clientUsing($client)
                ->complete($this->connection(), $this->request(), new NullCompletionStreamObserver());
            self::fail(ProviderUnreachableException::class . ' was not thrown.');
        } catch (ProviderUnreachableException $e) {
            self::assertSame('That address did not answer.', $e->getMessage());
        }
    }

    public function testObserverSeesTheAnswerAndTheWireCountGrow(): void
    {
        $first = "data: {\"choices\":[{\"delta\":{\"content\":\"He\"}}]}\n\n";
        $second = "data: {\"choices\":[{\"delta\":{\"content\":\"llo\"}}]}\n\n";
        $client = $this->clientAnswering(new MockResponse([$first, $second]));
        $seen = $this->recordingObserver();

        $client->complete($this->connection(), $this->request(), $seen);

        self::assertCount(2, $seen->reports);
        // The answer accumulates; each report carries everything decoded so
        // far, not just the newest delta.
        self::assertSame('He', $seen->reports[0]->answerSoFar);
        self::assertSame('Hello', $seen->reports[1]->answerSoFar);
        self::assertSame(\strlen($first), $seen->reports[0]->wireBytes);
        self::assertSame(\strlen($first . $second), $seen->reports[1]->wireBytes);
    }

    /**
     * The #327 seam. The provider stamps `length` on the choice when
     * `max_tokens` truncated the answer, and the client must relay it to the
     * observer -- that is what lets the debug log record why the answer stopped
     * rather than only that it did.
     */
    public function testTheObserverIsToldWhyGenerationStopped(): void
    {
        $answer = "data: {\"choices\":[{\"delta\":{\"content\":\"{}\"}}]}\n\n";
        $finish = "data: {\"choices\":[{\"delta\":{},\"finish_reason\":\"length\"}]}\n\n";
        $client = $this->clientAnswering(new MockResponse([$answer, $finish]));
        $seen = $this->recordingObserver();

        $client->complete($this->connection(), $this->request(), $seen);

        self::assertNull($seen->reports[0]->finishReason);
        self::assertSame('length', $seen->reports[\count($seen->reports) - 1]->finishReason);
    }

    /**
     * The #320 regression, at the seam that produced it. A reasoning model
     * streams its thinking as deltas with no content: the wire count must
     * climb while the answer stays empty, and the call must not be refused
     * for it. Before this change the transcript was retained and counted
     * against the answer's own 2 MiB cap, which failed real batches three
     * times running and killed the run.
     */
    public function testAReasoningStreamIsReportedWithoutBeingCharged(): void
    {
        // Thousands of small events, the way a thinking phase really
        // arrives -- not one giant one, which would have to be buffered
        // whole to be parsed and is legitimately refused.
        $event = 'data: ' . json_encode(
            ['choices' => [['delta' => ['reasoning' => str_repeat('thinking. ', 20)]]]],
            \JSON_THROW_ON_ERROR,
        ) . "\n\n";
        $reasoning = str_repeat($event, 10_000);
        $answer = "data: {\"choices\":[{\"delta\":{\"content\":\"done\"}}]}\n\n";

        self::assertGreaterThan(2_097_152, \strlen($reasoning), 'fixture must exceed the answer cap');

        $client = $this->clientAnswering(new MockResponse(str_split($reasoning . $answer, 50_000)));
        $seen = $this->recordingObserver();

        self::assertSame('done', $client->complete($this->connection(), $this->request(), $seen));
        self::assertSame('', $seen->reports[0]->answerSoFar);
        self::assertGreaterThan(2_097_152, $seen->reports[\count($seen->reports) - 1]->wireBytes);
    }

    /**
     * The answer cap still protects memory on the streaming path -- it is
     * only reasoning that stopped being charged to it. Without this, #320's
     * fix would have removed the bound rather than moved it.
     *
     * The bound is now the request's own answer budget rather than a flat 2 MB
     * (#437): 2048 requested tokens buy 16384 retained bytes.
     */
    public function testAnOversizedStreamedAnswerIsStillRefused(): void
    {
        $event = 'data: ' . json_encode(
            ['choices' => [['delta' => ['content' => str_repeat('a', 200)]]]],
            \JSON_THROW_ON_ERROR,
        ) . "\n\n";
        $client = $this->clientAnswering(new MockResponse(str_split(str_repeat($event, 12_000), 50_000)));

        $this->expectException(ProviderRunawayException::class);
        $this->expectExceptionMessage('That provider answered with more than 16384 bytes.');
        $client->complete($this->connection(), $this->request(), new NullCompletionStreamObserver());
    }

    /**
     * A flat 2 MB cap could never fire before `max_tokens` did: the largest
     * answer a request may ask for is a few tens of kilobytes, so the guard
     * was dead on the one path it was meant to cover -- a provider that
     * ignores `max_tokens` and generates until something stops it (#437). The
     * bound has to follow what was asked for.
     */
    public function testTheAnswerBoundFollowsWhatTheRequestAskedFor(): void
    {
        $event = 'data: ' . json_encode(
            ['choices' => [['delta' => ['content' => str_repeat('a', 200)]]]],
            \JSON_THROW_ON_ERROR,
        ) . "\n\n";
        $client = $this->clientAnswering(new MockResponse(str_split(str_repeat($event, 12_000), 50_000)));
        $request = new CompletionRequest('m', $this->messages(), 512, $this->schema(), true);

        $this->expectException(ProviderRunawayException::class);
        $this->expectExceptionMessage('That provider answered with more than 4096 bytes.');
        $client->complete($this->connection(), $request, new NullCompletionStreamObserver());
    }

    /**
     * The bound is inclusive: an answer that lands exactly on it was still
     * within what the request asked for, and refusing it would refuse a reply
     * the prompt legitimately made room for.
     */
    public function testAnAnswerExactlyOnTheBoundIsAccepted(): void
    {
        $answer = str_repeat('a', 16_384);
        $event = 'data: ' . json_encode(
            ['choices' => [['delta' => ['content' => $answer]]]],
            \JSON_THROW_ON_ERROR,
        ) . "\n\n";
        $client = $this->clientAnswering(new MockResponse(str_split($event, 4096)));

        self::assertSame(
            $answer,
            $client->complete($this->connection(), $this->request(), new NullCompletionStreamObserver()),
        );
    }

    /**
     * The runaway carries what arrived before it was cut, because that is what
     * the retry shows the model to break the loop. An empty partial answer
     * would send it back the same question unchanged (#437).
     */
    public function testARunawayCarriesThePartialAnswerItWasCutFrom(): void
    {
        $event = 'data: ' . json_encode(
            ['choices' => [['delta' => ['content' => str_repeat('a', 200)]]]],
            \JSON_THROW_ON_ERROR,
        ) . "\n\n";
        $client = $this->clientAnswering(new MockResponse(str_split(str_repeat($event, 12_000), 50_000)));

        try {
            $client->complete($this->connection(), $this->request(), new NullCompletionStreamObserver());
            self::fail('The runaway answer was accepted.');
        } catch (ProviderRunawayException $e) {
            self::assertStringStartsWith('aaaa', $e->partialAnswer());
        }
    }

    /**
     * A runaway is a per-call outcome like every other failure completeMany
     * folds, not an exception that aborts the read for the whole wave: the
     * sibling still delivers its answer (#344, #437).
     */
    public function testARunawayBecomesThatCallsOutcomeWithoutAbortingSiblings(): void
    {
        $event = 'data: ' . json_encode(
            ['choices' => [['delta' => ['content' => str_repeat('a', 200)]]]],
            \JSON_THROW_ON_ERROR,
        ) . "\n\n";
        $client = $this->clientReturning([
            new MockResponse(str_split(str_repeat($event, 12_000), 50_000)),
            $this->sseStream('{"picks":[]}'),
        ]);

        $outcomes = $client->completeMany($this->connection(), [
            $this->concurrentCall(new NullCompletionStreamObserver()),
            $this->concurrentCall(new NullCompletionStreamObserver()),
        ]);

        self::assertTrue($outcomes[0]->isFailure());
        self::assertInstanceOf(ProviderRunawayException::class, $outcomes[0]->cause());
        self::assertFalse($outcomes[1]->isFailure());
        self::assertSame('{"picks":[]}', $outcomes[1]->content());
    }

    /**
     * A runaway is not an unreachable provider. Reporting it as one sends the
     * reader to look at the network, when the model in fact answered at
     * length -- 8.2 MB of it, in the run that prompted #437 -- and simply
     * would not stop.
     */
    public function testARunawayIsNotReportedAsAnUnreachableProvider(): void
    {
        $event = 'data: ' . json_encode(
            ['choices' => [['delta' => ['content' => str_repeat('a', 200)]]]],
            \JSON_THROW_ON_ERROR,
        ) . "\n\n";
        $client = $this->clientAnswering(new MockResponse(str_split(str_repeat($event, 12_000), 50_000)));

        try {
            $client->complete($this->connection(), $this->request(), new NullCompletionStreamObserver());
            self::fail('The runaway answer was accepted.');
        } catch (ProviderRunawayException $e) {
            self::assertStringNotContainsString('did not answer', $e->getMessage());
        }
    }

    /**
     * The wall clock cutting a call that already reported `length` is a
     * runaway, and the generic transport message ("That address did not
     * answer.") is exactly wrong for it: the model had spent its whole ceiling
     * and 8.2 MB by then (#437).
     */
    public function testAWallClockCutAfterTheTokenCeilingIsReportedAsARunaway(): void
    {
        $event = 'data: ' . json_encode(
            ['choices' => [['delta' => ['content' => 'partial'], 'finish_reason' => 'length']]],
            \JSON_THROW_ON_ERROR,
        ) . "\n\n";
        $client = $this->clientAnswering(new MockResponse(
            (static function () use ($event): \Generator {
                yield $event;
                yield new TransportException('Maximum duration was reached.');
            })(),
        ));

        try {
            $client->complete($this->connection(), $this->request(), new NullCompletionStreamObserver());
            self::fail('The cut-off answer was accepted.');
        } catch (ProviderRunawayException $e) {
            self::assertStringNotContainsString('did not answer', $e->getMessage());
        }
    }

    /**
     * The other side of that decision: a connection that dies mid-answer is a
     * dead connection, however many bytes preceded it. Only the provider's own
     * `length` makes it a runaway.
     */
    public function testAConnectionResetMidAnswerStaysAnUnreachableProvider(): void
    {
        $event = 'data: ' . json_encode(
            ['choices' => [['delta' => ['content' => 'partial']]]],
            \JSON_THROW_ON_ERROR,
        ) . "\n\n";
        $client = $this->clientAnswering(new MockResponse(
            (static function () use ($event): \Generator {
                yield $event;
                yield new TransportException('Connection reset');
            })(),
        ));

        $this->expectException(ProviderUnreachableException::class);
        $this->expectExceptionMessage('That address did not answer.');
        $client->complete($this->connection(), $this->request(), new NullCompletionStreamObserver());
    }

    /**
     * #323: LM Studio delivers a reasoning model's whole answer under
     * `reasoning_content` and never populates `content`. The client recovers it
     * from the reasoning channel rather than failing the call as answerless.
     */
    public function testRecoversAnAnswerDeliveredOnlyInTheReasoningChannel(): void
    {
        $answer = 'data: ' . json_encode(
            ['choices' => [['delta' => ['reasoning_content' => '{"recommendations":[]}']]]],
            \JSON_THROW_ON_ERROR,
        ) . "\n\n";
        $finish = 'data: ' . json_encode(
            ['choices' => [['delta' => [], 'finish_reason' => 'stop']]],
            \JSON_THROW_ON_ERROR,
        ) . "\n\n";
        $client = $this->clientAnswering(new MockResponse([$answer, $finish, "data: [DONE]\n\n"]));

        self::assertSame(
            '{"recommendations":[]}',
            $client->complete($this->connection(), $this->request(), new NullCompletionStreamObserver()),
        );
    }

    /**
     * When a model populates both channels the content is the answer; the
     * reasoning stays the fallback, never overriding a real completion.
     */
    public function testContentIsPreferredWhenBothChannelsArrive(): void
    {
        $reasoning = 'data: ' . json_encode(
            ['choices' => [['delta' => ['reasoning_content' => 'discarded thinking']]]],
            \JSON_THROW_ON_ERROR,
        ) . "\n\n";
        $content = 'data: ' . json_encode(
            ['choices' => [['delta' => ['content' => '{"recommendations":[]}']]]],
            \JSON_THROW_ON_ERROR,
        ) . "\n\n";
        $client = $this->clientAnswering(new MockResponse([$reasoning, $content, "data: [DONE]\n\n"]));

        self::assertSame(
            '{"recommendations":[]}',
            $client->complete($this->connection(), $this->request(), new NullCompletionStreamObserver()),
        );
    }

    /**
     * The answerless refusal survives the recovery path: a stream that carries
     * neither a content nor a reasoning answer is still unreachable, not an
     * empty success.
     */
    public function testAStreamWithNeitherContentNorReasoningIsUnreachable(): void
    {
        $client = $this->clientAnswering(new MockResponse([
            'data: {"choices":[{"delta":{"role":"assistant"}}]}' . "\n\n",
            'data: [DONE]' . "\n\n",
        ]));

        $this->expectException(ProviderUnreachableException::class);
        $this->expectExceptionMessage('That provider answered without a completion.');
        $client->complete($this->connection(), $this->request(), new NullCompletionStreamObserver());
    }

    public function testAsksTheProviderNotToReasonWhenSuppressed(): void
    {
        $body = $this->captureRequestBody($this->suppressingRequest());

        self::assertSame(['effort' => 'none'], $body['reasoning']);
    }

    public function testOmitsTheReasoningFieldWhenNotSuppressed(): void
    {
        $body = $this->captureRequestBody($this->request());

        self::assertArrayNotHasKey('reasoning', $body);
    }

    public function testAsksTheProviderToIncludeUsageInTheStream(): void
    {
        $body = $this->captureRequestBody($this->request());

        self::assertSame(['include_usage' => true], $body['stream_options']);
    }

    public function testReportsTheProvidersUsageToTheObserver(): void
    {
        $client = $this->clientAnswering(new MockResponse([
            "data: {\"choices\":[{\"delta\":{\"content\":\"{}\"}}]}\n\n",
            "data: {\"choices\":[],\"usage\":{\"prompt_tokens\":11,\"completion_tokens\":4,\"cost\":0.002}}\n\n",
            "data: [DONE]\n\n",
        ]));
        $seen = $this->recordingObserver();

        $client->complete($this->connection(), $this->request(), $seen);

        $lastReport = $seen->reports[\count($seen->reports) - 1];
        self::assertSame(11, $lastReport->usage?->promptTokens);
        self::assertSame(2_000_000, $lastReport->usage->costNanoCredits);
    }

    public function testCompleteManyReturnsAnswersAlignedByIndex(): void
    {
        $client = $this->clientReturning([
            $this->sseStream('{"picks":[]}'),
            $this->sseStream('{"picks":[{"id":1,"score":9,"reason":"x"}]}'),
        ]);

        $outcomes = $client->completeMany($this->connection(), [
            $this->concurrentCall(new NullCompletionStreamObserver()),
            $this->concurrentCall(new NullCompletionStreamObserver()),
        ]);

        self::assertCount(2, $outcomes);
        self::assertFalse($outcomes[0]->isFailure());
        self::assertFalse($outcomes[1]->isFailure());
        self::assertSame('{"picks":[]}', $outcomes[0]->content());
        self::assertSame('{"picks":[{"id":1,"score":9,"reason":"x"}]}', $outcomes[1]->content());
    }

    public function testCompleteManyCarriesOneCallsTransportFailureWithoutAbortingSiblings(): void
    {
        $client = $this->clientReturning([
            $this->sseStream('{"picks":[]}'),
            new MockResponse('', ['http_code' => 500]),
        ]);

        $outcomes = $client->completeMany($this->connection(), [
            $this->concurrentCall(new NullCompletionStreamObserver()),
            $this->concurrentCall(new NullCompletionStreamObserver()),
        ]);

        // The sibling still decoded; the failed call carries its cause rather
        // than aborting the whole read.
        self::assertFalse($outcomes[0]->isFailure());
        self::assertSame('{"picks":[]}', $outcomes[0]->content());
        self::assertTrue($outcomes[1]->isFailure());
        self::assertInstanceOf(ProviderUnreachableException::class, $outcomes[1]->cause());
    }

    public function testCompleteManyMapsAuthRejectionToCredentialsRejected(): void
    {
        $client = $this->clientReturning([
            new MockResponse('', ['http_code' => 401]),
        ]);

        $outcomes = $client->completeMany($this->connection(), [
            $this->concurrentCall(new NullCompletionStreamObserver()),
        ]);

        self::assertTrue($outcomes[0]->isFailure());
        self::assertInstanceOf(CredentialsRejectedException::class, $outcomes[0]->cause());
    }

    /**
     * completeMany's whole atomicity promise -- one failed call never aborts
     * the read for its siblings -- rests on advance() converting even a raw
     * Symfony transport exception into that call's own failure outcome. Every
     * other completeMany failure test above raises through an HTTP status
     * (guardStatus's domain exceptions); this one is the only one that goes
     * through the multiplexed loop's own generic ExceptionInterface catch, by
     * having the connection itself die mid-stream.
     */
    public function testCompleteManyConvertsARawTransportFailureIntoThatCallsOutcomeWithoutAbortingSiblings(): void
    {
        $failingBody = (static function (): \Generator {
            yield 'data: {"choices":[{"delta":{"content":"par"}}]}' . "\n\n";
            yield new TransportException('Connection reset');
        })();
        $client = $this->clientReturning([
            new MockResponse($failingBody),
            $this->sseStream('{"picks":[]}'),
        ]);

        $outcomes = $client->completeMany($this->connection(), [
            $this->concurrentCall(new NullCompletionStreamObserver()),
            $this->concurrentCall(new NullCompletionStreamObserver()),
        ]);

        self::assertTrue($outcomes[0]->isFailure());
        self::assertInstanceOf(ProviderUnreachableException::class, $outcomes[0]->cause());
        self::assertSame('That address did not answer.', $outcomes[0]->cause()->getMessage());
        self::assertFalse($outcomes[1]->isFailure());
        self::assertSame('{"picks":[]}', $outcomes[1]->content());
    }

    /**
     * The sibling of the test above, one stage earlier: here the connection
     * refusal happens at request() itself, before there is any response to
     * read a chunk from at all (MockHttpClient's factory throws synchronously,
     * exactly as a refused TCP connection would). fireRequests() has to catch
     * this one directly and bank it as that call's own outcome -- unlike the
     * mid-stream case, there is no response object for advance() to key it by,
     * so this exercises a different catch block from the mid-stream test
     * above. completeMany() must not throw for this either: it stays exactly
     * as available to the caller as any other per-call failure, which is what
     * lets complete() (a one-call completeMany() wave, #344) recover it back
     * into a thrown exception in testTransportErrorsAreUnreachable.
     */
    public function testCompleteManySettlesARequestPhaseFailureAsThatCallsOutcome(): void
    {
        $client = new MockHttpClient(static function (): MockResponse {
            throw new TransportException('Connection refused');
        });

        $outcomes = $this->clientUsing($client)->completeMany($this->connection(), [
            $this->concurrentCall(new NullCompletionStreamObserver()),
        ]);

        self::assertTrue($outcomes[0]->isFailure());
        self::assertInstanceOf(ProviderUnreachableException::class, $outcomes[0]->cause());
        self::assertSame('That address did not answer.', $outcomes[0]->cause()->getMessage());
    }

    /**
     * A failed call's own response must not linger open once its outcome is
     * settled -- the transport-failure branches cancel it explicitly rather
     * than counting on the connection to close itself.
     */
    public function testCompleteManyCancelsTheFailedCallsOwnResponse(): void
    {
        $client = new ResponseCapturingHttpClient(new MockResponse('', ['http_code' => 500]));

        $outcomes = $this->clientUsing($client)->completeMany($this->connection(), [
            $this->concurrentCall(new NullCompletionStreamObserver()),
        ]);

        self::assertTrue($outcomes[0]->isFailure());
        self::assertTrue($client->lastResponse?->getInfo('canceled'));
    }

    /**
     * The multiplexed loop routes every chunk back to the reader and observer
     * of the response it belongs to. If it crossed the streams, one call's
     * answer would leak into the other's outcome — so each observer must have
     * seen only its own answer, and each outcome must carry its own.
     */
    public function testCompleteManyRoutesEachStreamToItsOwnReaderAndObserver(): void
    {
        $client = $this->clientReturning([
            $this->sseStream('{"a":1}'),
            $this->sseStream('{"b":2}'),
        ]);
        $first = $this->recordingObserver();
        $second = $this->recordingObserver();

        $outcomes = $client->completeMany($this->connection(), [
            $this->concurrentCall($first),
            $this->concurrentCall($second),
        ]);

        self::assertSame('{"a":1}', $outcomes[0]->content());
        self::assertSame('{"b":2}', $outcomes[1]->content());
        self::assertNotSame([], $first->reports);
        self::assertNotSame([], $second->reports);
        self::assertSame('{"a":1}', $first->reports[\count($first->reports) - 1]->answerSoFar);
        self::assertSame('{"b":2}', $second->reports[\count($second->reports) - 1]->answerSoFar);
    }

    /**
     * A stream that carries neither a content nor a reasoning answer is an
     * empty completion, and on the concurrent path that becomes the call's own
     * failure outcome rather than an exception that would lose its siblings.
     */
    public function testCompleteManyRecordsAnEmptyCompletionAsThatCallsFailure(): void
    {
        $client = $this->clientReturning([
            $this->sseStream('{"picks":[]}'),
            new MockResponse([
                'data: {"choices":[{"delta":{"role":"assistant"}}]}' . "\n\n",
                "data: [DONE]\n\n",
            ]),
        ]);

        $outcomes = $client->completeMany($this->connection(), [
            $this->concurrentCall(new NullCompletionStreamObserver()),
            $this->concurrentCall(new NullCompletionStreamObserver()),
        ]);

        self::assertFalse($outcomes[0]->isFailure());
        self::assertTrue($outcomes[1]->isFailure());
        self::assertInstanceOf(ProviderUnreachableException::class, $outcomes[1]->cause());
    }

    /** @return array<string, mixed> the decoded JSON request body */
    private function captureRequestBody(CompletionRequest $request): array
    {
        $seen = null;
        $client = new MockHttpClient(function (string $method, string $url, array $options) use (&$seen): MockResponse {
            $seen = $options['body'] ?? '';

            return new MockResponse('{"choices":[{"message":{"content":"{\"recommendations\":[]}"}}]}');
        });

        $this->clientUsing($client)->complete($this->connection(), $request, new NullCompletionStreamObserver());
        self::assertIsString($seen);

        $decoded = json_decode($seen, true);
        self::assertIsArray($decoded);

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /** @return CompletionStreamObserver&object{reports: list<CompletionStreamProgress>} */
    private function recordingObserver(): CompletionStreamObserver
    {
        return new class implements CompletionStreamObserver {
            /** @var list<CompletionStreamProgress> */
            public array $reports = [];

            public function streamProgressed(CompletionStreamProgress $progress): void
            {
                $this->reports[] = $progress;
            }
        };
    }
}
