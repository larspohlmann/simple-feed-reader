<?php

declare(strict_types=1);

namespace App\Tests\Service\Ai\Completion\ChatCompletionClient;

use App\Service\Ai\Completion\ChatCompletionClient\OpenAiCompatibleChatClient;
use App\Service\Ai\Completion\CompletionBodyDecoder;
use App\Service\Ai\Completion\CompletionStreamHeartbeat\CompletionStreamHeartbeatInterface;
use App\Service\Ai\Completion\CompletionStreamObserver\CompletionStreamObserverInterface;
use App\Service\Ai\Completion\CompletionStreamObserver\NullCompletionStreamObserver;
use App\Service\Ai\Completion\Model\CompletionOutcomeModel;
use App\Service\Ai\Completion\Model\CompletionRequestModel;
use App\Service\Ai\Completion\Model\CompletionStreamProgressModel;
use App\Service\Ai\Completion\Model\JsonSchemaModel;
use App\Service\Ai\Completion\Model\Reasoning;
use App\Service\Ai\Completion\Pass\ConcurrentCompletion;
use App\Service\Ai\Exception\CredentialsRejectedException;
use App\Service\Ai\Exception\ProviderRunawayException;
use App\Service\Ai\Exception\ProviderUnreachableException;
use App\Service\Ai\Exception\RetryableProviderException;
use App\Service\Ai\Model\ProviderConnectionModel;
use App\Service\Ai\Model\ProviderCredentialsModel;
use App\Service\Ai\Model\ProviderTimeoutsModel;
use App\Tests\Support\CountingCompletionStreamHeartbeat;
use App\Tests\Support\NullCompletionStreamHeartbeat;
use App\Tests\Support\ResponseCapturingHttpClient;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpClient\Response\ResponseStream;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Contracts\HttpClient\ResponseStreamInterface;

final class OpenAiCompatibleChatClientTest extends TestCase
{
    private function credentials(): ProviderCredentialsModel
    {
        return ProviderCredentialsModel::fromStoredConfiguration('https://api.example.test/v1', 'sk-test');
    }

    private function connection(): ProviderConnectionModel
    {
        return new ProviderConnectionModel($this->credentials(), ProviderTimeoutsModel::standard());
    }

    /** @return list<array{role: string, content: string}> */
    private function messages(): array
    {
        return [['role' => 'user', 'content' => 'Rank these entries.']];
    }

    private function request(): CompletionRequestModel
    {
        return new CompletionRequestModel('m', $this->messages(), 2048, $this->schema(), Reasoning::Allowed);
    }

    private function suppressingRequest(): CompletionRequestModel
    {
        return new CompletionRequestModel('m', $this->messages(), 2048, $this->schema(), Reasoning::Suppressed);
    }

    private function schema(): JsonSchemaModel
    {
        return new JsonSchemaModel('test_schema', ['type' => 'object']);
    }

    private function clientUsing(HttpClientInterface $httpClient): OpenAiCompatibleChatClient
    {
        return $this->clientReportingTo($httpClient, new NullCompletionStreamHeartbeat());
    }

    private function clientReportingTo(
        HttpClientInterface $httpClient,
        CompletionStreamHeartbeatInterface $heartbeat,
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

    /** The one outcome of a single-call wave: a spoiled reply is content with a cause, not an exception. */
    private function soleOutcomeOf(
        OpenAiCompatibleChatClient $client,
        CompletionRequestModel $request,
    ): CompletionOutcomeModel {
        return $client->completeMany($this->connection(), [
            new ConcurrentCompletion($request, new NullCompletionStreamObserver()),
        ])[0];
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

    private function concurrentCall(CompletionStreamObserverInterface $observer): ConcurrentCompletion
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

        // Pinned as numbers, not constants, so swapping the idle and wall bounds or dropping max_duration fails here.
        self::assertSame(180.0, $seen['timeout']);
        self::assertSame(600.0, $seen['max_duration']);

        // The whole body, so `max_tokens` (the one guard that prevents spend) cannot be dropped silently; its value is
        // the caller's, never a constant of the client.
        $decodedBody = json_decode($seen['body'], true);
        self::assertSame([
            'model' => 'm',
            'messages' => $this->messages(),
            'response_format' => [
                'type' => 'json_schema',
                'json_schema' => [
                    'name' => 'test_schema',
                    'strict' => true,
                    'schema' => ['type' => 'object'],
                ],
            ],
            'stream' => true,
            'stream_options' => ['include_usage' => true],
            'max_tokens' => 2048,
        ], $decodedBody);
    }

    /** Only the transport knows a chunk arrived; without this ping a worker inside a long call would look dead. */
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
     * Both profiles, because a wiring that read one fixed profile would pass for a standard connection and keep
     * failing the slow local model. `timeout` is Symfony's idle bound, `max_duration` the wall clock.
     *
     * @param array<string, mixed> $expected
     */
    #[DataProvider('profileOptions')]
    public function testTheRequestCarriesTheConnectionsOwnBounds(
        ProviderTimeoutsModel $timeouts,
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
            new ProviderConnectionModel($this->credentials(), $timeouts),
            $this->request(),
            new NullCompletionStreamObserver(),
        );

        self::assertSame($expected['timeout'], $seen['timeout']);
        self::assertSame($expected['max_duration'], $seen['max_duration']);
    }

    /**
     * @return iterable<string, array{ProviderTimeoutsModel, array<string, mixed>}>
     */
    public static function profileOptions(): iterable
    {
        yield 'standard' => [
            ProviderTimeoutsModel::standard(),
            ['timeout' => 180.0, 'max_duration' => 600.0],
        ];
        yield 'slow model' => [
            ProviderTimeoutsModel::forSlowModel(),
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
            new ProviderConnectionModel($this->credentials(), ProviderTimeoutsModel::forSlowModel()),
            $this->request(),
            new NullCompletionStreamObserver(),
        );
    }

    /** A keyless credential (a local server) sends no `Bearer ` at all, and every other header still goes out. */
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

        $credentials = ProviderCredentialsModel::fromStoredConfiguration('https://api.example.test/v1', '');
        $this->clientUsing($client)->complete(
            new ProviderConnectionModel($credentials, ProviderTimeoutsModel::standard()),
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
     * A provider that ignores `stream: true` answers with the blocking envelope. Shape only: a real one must finish
     * within the first-byte bound (ProviderTimeoutsModel says why).
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
     * A silent stream is aborted after the idle bound as unreachable, not after the wall clock. MockHttpClient turns
     * an empty string yielded by a body generator into a timeout chunk.
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
        } catch (ProviderUnreachableException $exception) {
            self::assertSame('That provider sent nothing for more than 180 seconds.', $exception->getMessage());
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
     * A redirect is refused, never followed: following would hand the API key to the `Location` host. MockHttpClient
     * never follows redirects, so this does not pin the `max_redirects: 0` option itself.
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

    public function testA429BecomesARetryableOutcomeCarryingRetryAfter(): void
    {
        $client = $this->clientAnswering(
            new MockResponse('{"error":"slow down"}', [
                'http_code' => 429,
                'response_headers' => ['retry-after' => '9'],
            ]),
        );

        $outcome = $this->soleOutcomeOf($client, $this->request());

        self::assertTrue($outcome->isFailure());
        self::assertTrue($outcome->isRetryable());
        self::assertInstanceOf(RetryableProviderException::class, $outcome->cause());
        self::assertSame(9, $outcome->retryAfterSeconds());
    }

    public function testA503IsRetryableWithNoRetryAfter(): void
    {
        $client = $this->clientAnswering(new MockResponse('', ['http_code' => 503]));

        $outcome = $this->soleOutcomeOf($client, $this->request());

        self::assertTrue($outcome->isRetryable());
        self::assertNull($outcome->retryAfterSeconds());
    }

    public function testA502IsRetryable(): void
    {
        $client = $this->clientAnswering(new MockResponse('', ['http_code' => 502]));

        $outcome = $this->soleOutcomeOf($client, $this->request());

        self::assertTrue($outcome->isRetryable());
        self::assertInstanceOf(RetryableProviderException::class, $outcome->cause());
    }

    public function testA504IsRetryable(): void
    {
        $client = $this->clientAnswering(new MockResponse('', ['http_code' => 504]));

        $outcome = $this->soleOutcomeOf($client, $this->request());

        self::assertTrue($outcome->isRetryable());
        self::assertInstanceOf(RetryableProviderException::class, $outcome->cause());
    }

    public function testA500IsStillANonRetryableUnreachableFailure(): void
    {
        $client = $this->clientAnswering(new MockResponse('', ['http_code' => 500]));

        $outcome = $this->soleOutcomeOf($client, $this->request());

        self::assertTrue($outcome->isFailure());
        self::assertFalse($outcome->isRetryable());
        self::assertInstanceOf(ProviderUnreachableException::class, $outcome->cause());
    }

    public function testANonNumericRetryAfterFallsBackToNoHint(): void
    {
        $client = $this->clientAnswering(new MockResponse('', [
            'http_code' => 429,
            'response_headers' => ['retry-after' => 'Wed, 21 Oct 2026 07:28:00 GMT'],
        ]));

        self::assertNull($this->soleOutcomeOf($client, $this->request())->retryAfterSeconds());
    }

    /** Valid JSON padded past MAXIMUM_RETAINED_BYTES: the retained-size bound is the only thing that refuses it. */
    public function testAnOversizedAnswerIsRefusedAsARunaway(): void
    {
        $body = '{"choices":[{"message":{"content":"' . str_repeat('a', 2_100_000) . '"}}]}';
        $client = $this->clientAnswering(new MockResponse(str_split($body, 50_000)));

        self::assertInstanceOf(
            ProviderRunawayException::class,
            $this->soleOutcomeOf($client, $this->request())->cause(),
        );
    }

    /** complete(), a one-call completeMany() wave, still throws for a failure with no response: a refused request(). */
    public function testTransportErrorsAreUnreachable(): void
    {
        $client = new MockHttpClient(static function (): MockResponse {
            throw new TransportException('Connection refused');
        });

        // The exact message: contentOf() raises the same exception type of its own for an empty reader.
        try {
            $this->clientUsing($client)
                ->complete($this->connection(), $this->request(), new NullCompletionStreamObserver());
            self::fail(ProviderUnreachableException::class . ' was not thrown.');
        } catch (ProviderUnreachableException $exception) {
            self::assertSame('That address did not answer.', $exception->getMessage());
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

    /** The client relays the provider's `finish_reason`, so the debug log records why the answer stopped. */
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

    /** Reasoning deltas carry no content: the wire count climbs, the answer stays empty, the call is not refused. */
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

    /** The answer bound still refuses on the streaming path: 2048 requested tokens buy 16384 retained bytes. */
    public function testAnOversizedStreamedAnswerIsStillRefused(): void
    {
        $event = 'data: ' . json_encode(
            ['choices' => [['delta' => ['content' => str_repeat('a', 200)]]]],
            \JSON_THROW_ON_ERROR,
        ) . "\n\n";
        $client = $this->clientAnswering(new MockResponse(str_split(str_repeat($event, 12_000), 50_000)));

        $cause = $this->soleOutcomeOf($client, $this->request())->cause();
        self::assertInstanceOf(ProviderRunawayException::class, $cause);
        self::assertSame('That provider answered with more than 16384 bytes.', $cause->getMessage());
    }

    /** The bound follows what the request asked for: a flat cap never fired before `max_tokens` did. */
    public function testTheAnswerBoundFollowsWhatTheRequestAskedFor(): void
    {
        $event = 'data: ' . json_encode(
            ['choices' => [['delta' => ['content' => str_repeat('a', 200)]]]],
            \JSON_THROW_ON_ERROR,
        ) . "\n\n";
        $client = $this->clientAnswering(new MockResponse(str_split(str_repeat($event, 12_000), 50_000)));
        $request = new CompletionRequestModel('m', $this->messages(), 512, $this->schema(), Reasoning::Suppressed);

        self::assertSame(
            'That provider answered with more than 4096 bytes.',
            $this->soleOutcomeOf($client, $request)->cause()->getMessage(),
        );
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
     * would send it back the same question unchanged.
     */
    public function testARunawayCarriesThePartialAnswerItWasCutFrom(): void
    {
        $event = 'data: ' . json_encode(
            ['choices' => [['delta' => ['content' => str_repeat('a', 200)]]]],
            \JSON_THROW_ON_ERROR,
        ) . "\n\n";
        $client = $this->clientAnswering(new MockResponse(str_split(str_repeat($event, 12_000), 50_000)));

        $cause = $this->soleOutcomeOf($client, $this->request())->cause();
        self::assertInstanceOf(ProviderRunawayException::class, $cause);
        self::assertStringStartsWith('aaaa', $cause->partialAnswer());
    }

    /**
     * A runaway is that call's outcome and the sibling still answers. isFailure() stays false, because the endpoint
     * answered; the caller's parser rejects the reply.
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

        self::assertFalse($outcomes[0]->isFailure());
        self::assertInstanceOf(ProviderRunawayException::class, $outcomes[0]->cause());
        self::assertStringStartsWith('aaaa', $outcomes[0]->content());
        self::assertFalse($outcomes[1]->isFailure());
        self::assertSame('{"picks":[]}', $outcomes[1]->content());
    }

    /** A runaway is not an unreachable provider: the model answered at length (8.2 MB in #437) and would not stop. */
    public function testARunawayIsNotReportedAsAnUnreachableProvider(): void
    {
        $event = 'data: ' . json_encode(
            ['choices' => [['delta' => ['content' => str_repeat('a', 200)]]]],
            \JSON_THROW_ON_ERROR,
        ) . "\n\n";
        $client = $this->clientAnswering(new MockResponse(str_split(str_repeat($event, 12_000), 50_000)));

        self::assertStringNotContainsString(
            'did not answer',
            $this->soleOutcomeOf($client, $this->request())->cause()->getMessage(),
        );
    }

    /** A wall-clock cut after the call reported `length` is a runaway, not "That address did not answer.". */
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

        $cause = $this->soleOutcomeOf($client, $this->request())->cause();
        self::assertInstanceOf(ProviderRunawayException::class, $cause);
        self::assertStringNotContainsString('did not answer', $cause->getMessage());
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
     * LM Studio delivers a reasoning model's whole answer under
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
     * The only test through advance()'s generic ExceptionInterface catch (the others fail by HTTP status): the
     * connection dies mid-stream, and the sibling still answers.
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
     * The request-phase sibling of the test above: request() itself refuses (MockHttpClient's factory throws, as a
     * refused connection would), so fireRequests() settles it as that call's outcome and completeMany() does not throw.
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

    /** Each chunk reaches its own call's reader and observer: crossed streams would leak one answer into the other. */
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

    public function testCompleteManySettlesACallWhoseStreamNeverClosedAsAFailure(): void
    {
        $client = $this->clientUsing(new class ([$this->sseStream('{"picks":[]}')]) extends MockHttpClient {
            public function stream(
                ResponseInterface|iterable $responses,
                ?float $timeout = null,
            ): ResponseStreamInterface {
                return new ResponseStream((static function (): \Generator {
                    yield from [];
                })());
            }
        });

        $outcomes = $client->completeMany($this->connection(), [
            $this->concurrentCall(new NullCompletionStreamObserver()),
        ]);

        self::assertCount(1, $outcomes);
        self::assertInstanceOf(CompletionOutcomeModel::class, $outcomes[0]);
        self::assertTrue($outcomes[0]->isFailure());
        self::assertInstanceOf(ProviderUnreachableException::class, $outcomes[0]->cause());
    }

    /** @return array<string, mixed> the decoded JSON request body */
    private function captureRequestBody(CompletionRequestModel $request): array
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

    /** @return CompletionStreamObserverInterface&object{reports: list<CompletionStreamProgressModel>} */
    private function recordingObserver(): CompletionStreamObserverInterface
    {
        return new class implements CompletionStreamObserverInterface {
            /** @var list<CompletionStreamProgressModel> */
            public array $reports = [];

            public function streamProgressed(CompletionStreamProgressModel $progress): void
            {
                $this->reports[] = $progress;
            }
        };
    }

    public function testARequestPhaseFailureCarriesNoErrorCode(): void
    {
        $client = new MockHttpClient(static function (): MockResponse {
            throw new TransportException('Connection refused');
        });

        $outcomes = $this->clientUsing($client)->completeMany($this->connection(), [
            $this->concurrentCall(new NullCompletionStreamObserver()),
        ]);

        self::assertSame(0, $outcomes[0]->cause()->getCode());
    }
}
