<?php

declare(strict_types=1);

namespace App\Tests\Service\Recommendation;

use App\Service\Ai\Exception\CredentialsRejectedException;
use App\Service\Ai\Exception\ProviderUnreachableException;
use App\Service\Ai\ProviderCredentials;
use App\Service\Recommendation\CompletionBodyDecoder;
use App\Service\Recommendation\OpenAiCompatibleChatClient;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

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

    private function clientAnswering(MockResponse $response): OpenAiCompatibleChatClient
    {
        return new OpenAiCompatibleChatClient(
            new MockHttpClient($response),
            new CompletionBodyDecoder(),
            'SimpleFeedReader/1.0',
        );
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
            ];

            return new MockResponse([
                'data: {"choices":[{"delta":{"role":"assistant"}}]}' . "\n\n",
                'data: {"choices":[{"delta":{"content":"{\"recommend"}}]}' . "\n\n",
                'data: {"choices":[{"delta":{"content":"ations\":[]}"}}]}' . "\n\n",
                'data: [DONE]' . "\n\n",
            ]);
        });

        $content = (new OpenAiCompatibleChatClient($client, new CompletionBodyDecoder(), 'SimpleFeedReader/1.0'))
            ->complete($this->credentials(), 'm', $this->messages());

        self::assertSame('{"recommendations":[]}', $content);

        /** @var array{method: string, url: string, headers: array<int, string>, body: string} $seen */
        self::assertSame('POST', $seen['method']);
        self::assertSame('https://api.example.test/v1/chat/completions', $seen['url']);
        self::assertContains('Authorization: Bearer sk-test', $seen['headers']);
        self::assertContains('Accept-Encoding: identity', $seen['headers']);

        $decodedBody = json_decode($seen['body'], true);
        self::assertSame([
            'model' => 'm',
            'messages' => $this->messages(),
            'response_format' => ['type' => 'json_object'],
            'stream' => true,
        ], $decodedBody);
    }

    /**
     * A provider that ignores `stream: true` answers with the blocking envelope;
     * the client must accept it exactly as it did before #312.
     */
    public function testABlockingEnvelopeAnswerStillWorks(): void
    {
        $client = $this->clientAnswering(
            new MockResponse('{"choices":[{"message":{"content":"{\"recommendations\":[]}"}}]}'),
        );

        self::assertSame(
            '{"recommendations":[]}',
            $client->complete($this->credentials(), 'm', $this->messages()),
        );
    }

    /**
     * The point of #312: a stream that goes silent is aborted after the
     * inactivity window and surfaces as the same typed transport failure the
     * #308 retry pipeline already handles — not after the full 120 s budget.
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
        $client = $this->clientAnswering(new MockResponse($body()));

        $this->expectException(ProviderUnreachableException::class);
        $this->expectExceptionMessage('That provider stopped streaming for more than 30 seconds.');
        $client->complete($this->credentials(), 'm', $this->messages());
    }

    public function testRejectedCredentialsBecomeTheTypedException(): void
    {
        $client = $this->clientAnswering(new MockResponse('{"error":"nope"}', ['http_code' => 401]));

        $this->expectException(CredentialsRejectedException::class);
        $this->expectExceptionMessage('That provider refused the API key.');
        $client->complete($this->credentials(), 'm', $this->messages());
    }

    public function testNonJsonEnvelopeIsUnreachable(): void
    {
        $client = $this->clientAnswering(new MockResponse('not json'));

        $this->expectException(ProviderUnreachableException::class);
        $client->complete($this->credentials(), 'm', $this->messages());
    }

    public function testEnvelopeWithoutContentIsUnreachable(): void
    {
        $client = $this->clientAnswering(new MockResponse('{"choices":[]}'));

        $this->expectException(ProviderUnreachableException::class);
        $client->complete($this->credentials(), 'm', $this->messages());
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
        $client->complete($this->credentials(), 'm', $this->messages());
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
        $client->complete($this->credentials(), 'm', $this->messages());
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
        (new OpenAiCompatibleChatClient($client, new CompletionBodyDecoder(), 'SimpleFeedReader/1.0'))
            ->complete($this->credentials(), 'm', $this->messages());
    }

    public function testAForbiddenAnswerIsAlsoARejectedKey(): void
    {
        $client = $this->clientAnswering(new MockResponse('{"error":"nope"}', ['http_code' => 403]));

        $this->expectException(CredentialsRejectedException::class);
        $this->expectExceptionMessage('That provider refused the API key.');
        $client->complete($this->credentials(), 'm', $this->messages());
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
    public function testAnOversizedBodyIsUnreachable(): void
    {
        $body = '{"choices":[{"message":{"content":"' . str_repeat('a', 2_100_000) . '"}}]}';
        $client = $this->clientAnswering(new MockResponse(str_split($body, 50_000)));

        $this->expectException(ProviderUnreachableException::class);
        $client->complete($this->credentials(), 'm', $this->messages());
    }

    public function testTransportErrorsAreUnreachable(): void
    {
        $client = new MockHttpClient(static function (): MockResponse {
            throw new TransportException('Connection refused');
        });

        $this->expectException(ProviderUnreachableException::class);
        (new OpenAiCompatibleChatClient($client, new CompletionBodyDecoder(), 'SimpleFeedReader/1.0'))
            ->complete($this->credentials(), 'm', $this->messages());
    }
}
