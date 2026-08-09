<?php

declare(strict_types=1);

namespace App\Tests\Service\Recommendation;

use App\Service\Ai\Exception\CredentialsRejectedException;
use App\Service\Ai\Exception\ProviderUnreachableException;
use App\Service\Ai\ProviderCredentials;
use App\Service\Recommendation\CompletionBodyDecoder;
use App\Service\Recommendation\CompletionRequest;
use App\Service\Recommendation\CompletionStreamProgress;
use App\Service\Recommendation\CompletionStreamObserver;
use App\Service\Recommendation\JsonSchema;
use App\Service\Recommendation\NullCompletionStreamObserver;
use App\Service\Recommendation\OpenAiCompatibleChatClient;
use App\Tests\Support\ResponseCapturingHttpClient;
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

    /** @return list<array{role: string, content: string}> */
    private function messages(): array
    {
        return [['role' => 'user', 'content' => 'Rank these entries.']];
    }

    private function request(): CompletionRequest
    {
        return new CompletionRequest('m', $this->messages(), 2048, $this->schema());
    }

    private function schema(): JsonSchema
    {
        return new JsonSchema('test_schema', ['type' => 'object']);
    }

    private function clientUsing(HttpClientInterface $httpClient): OpenAiCompatibleChatClient
    {
        return new OpenAiCompatibleChatClient($httpClient, new CompletionBodyDecoder(), 'SimpleFeedReader/1.0');
    }

    private function clientAnswering(MockResponse $response): OpenAiCompatibleChatClient
    {
        return $this->clientUsing(new MockHttpClient($response));
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
            ->complete($this->credentials(), $this->request(), new NullCompletionStreamObserver());

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
            'max_tokens' => 2048,
        ], $decodedBody);
    }

    /**
     * A provider that ignores `stream: true` answers with the blocking envelope;
     * the client must accept it exactly as it did before #312.
     *
     * Shape only, not timing: the MockResponse answers instantly, so this says
     * nothing about whether such a provider can still finish in time. It
     * cannot, past INACTIVITY_TIMEOUT_SECONDS — that bound covers the wait for
     * the response headers too, and the constant's own comment records why the
     * branch accepts that.
     */
    public function testABlockingEnvelopeAnswerStillWorks(): void
    {
        $client = $this->clientAnswering(
            new MockResponse('{"choices":[{"message":{"content":"{\"recommendations\":[]}"}}]}'),
        );

        self::assertSame(
            '{"recommendations":[]}',
            $client->complete($this->credentials(), $this->request(), new NullCompletionStreamObserver()),
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
                ->complete($this->credentials(), $this->request(), new NullCompletionStreamObserver());
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
        $client->complete($this->credentials(), $this->request(), new NullCompletionStreamObserver());
    }

    public function testNonJsonEnvelopeIsUnreachable(): void
    {
        $client = $this->clientAnswering(new MockResponse('not json'));

        $this->expectException(ProviderUnreachableException::class);
        $client->complete($this->credentials(), $this->request(), new NullCompletionStreamObserver());
    }

    public function testEnvelopeWithoutContentIsUnreachable(): void
    {
        $client = $this->clientAnswering(new MockResponse('{"choices":[]}'));

        $this->expectException(ProviderUnreachableException::class);
        $client->complete($this->credentials(), $this->request(), new NullCompletionStreamObserver());
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
        $client->complete($this->credentials(), $this->request(), new NullCompletionStreamObserver());
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
        $client->complete($this->credentials(), $this->request(), new NullCompletionStreamObserver());
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
            ->complete($this->credentials(), $this->request(), new NullCompletionStreamObserver());
    }

    public function testAForbiddenAnswerIsAlsoARejectedKey(): void
    {
        $client = $this->clientAnswering(new MockResponse('{"error":"nope"}', ['http_code' => 403]));

        $this->expectException(CredentialsRejectedException::class);
        $this->expectExceptionMessage('That provider refused the API key.');
        $client->complete($this->credentials(), $this->request(), new NullCompletionStreamObserver());
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
    public function testAnOversizedAnswerIsUnreachable(): void
    {
        $body = '{"choices":[{"message":{"content":"' . str_repeat('a', 2_100_000) . '"}}]}';
        $client = $this->clientAnswering(new MockResponse(str_split($body, 50_000)));

        $this->expectException(ProviderUnreachableException::class);
        $client->complete($this->credentials(), $this->request(), new NullCompletionStreamObserver());
    }

    public function testTransportErrorsAreUnreachable(): void
    {
        $client = new MockHttpClient(static function (): MockResponse {
            throw new TransportException('Connection refused');
        });

        $this->expectException(ProviderUnreachableException::class);
        $this->clientUsing($client)
            ->complete($this->credentials(), $this->request(), new NullCompletionStreamObserver());
    }

    public function testObserverSeesTheAnswerAndTheWireCountGrow(): void
    {
        $first = "data: {\"choices\":[{\"delta\":{\"content\":\"He\"}}]}\n\n";
        $second = "data: {\"choices\":[{\"delta\":{\"content\":\"llo\"}}]}\n\n";
        $client = $this->clientAnswering(new MockResponse([$first, $second]));
        $seen = $this->recordingObserver();

        $client->complete($this->credentials(), $this->request(), $seen);

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

        $client->complete($this->credentials(), $this->request(), $seen);

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

        self::assertSame('done', $client->complete($this->credentials(), $this->request(), $seen));
        self::assertSame('', $seen->reports[0]->answerSoFar);
        self::assertGreaterThan(2_097_152, $seen->reports[\count($seen->reports) - 1]->wireBytes);
    }

    /**
     * The answer cap still protects memory on the streaming path -- it is
     * only reasoning that stopped being charged to it. Without this, #320's
     * fix would have removed the bound rather than moved it.
     */
    public function testAnOversizedStreamedAnswerIsStillUnreachable(): void
    {
        $event = 'data: ' . json_encode(
            ['choices' => [['delta' => ['content' => str_repeat('a', 200)]]]],
            \JSON_THROW_ON_ERROR,
        ) . "\n\n";
        $client = $this->clientAnswering(new MockResponse(str_split(str_repeat($event, 12_000), 50_000)));

        $this->expectException(ProviderUnreachableException::class);
        $this->expectExceptionMessage('That provider answered with more than 2097152 bytes.');
        $client->complete($this->credentials(), $this->request(), new NullCompletionStreamObserver());
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
