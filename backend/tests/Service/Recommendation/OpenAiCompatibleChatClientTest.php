<?php

declare(strict_types=1);

namespace App\Tests\Service\Recommendation;

use App\Service\Ai\Exception\CredentialsRejectedException;
use App\Service\Ai\Exception\ProviderUnreachableException;
use App\Service\Ai\ProviderCredentials;
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
        return new OpenAiCompatibleChatClient(new MockHttpClient($response), 'SimpleFeedReader/1.0');
    }

    public function testReturnsTheAssistantContent(): void
    {
        $seen = [];
        $client = new MockHttpClient(function (string $method, string $url, array $options) use (&$seen): MockResponse {
            $seen = [
                'method' => $method,
                'url' => $url,
                'headers' => $options['headers'] ?? [],
                'body' => $options['body'] ?? null,
            ];

            return new MockResponse('{"choices":[{"message":{"content":"{\"recommendations\":[]}"}}]}');
        });

        $content = (new OpenAiCompatibleChatClient($client, 'SimpleFeedReader/1.0'))
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
        ], $decodedBody);
    }

    public function testRejectedCredentialsBecomeTheTypedException(): void
    {
        $client = $this->clientAnswering(new MockResponse('{"error":"nope"}', ['http_code' => 401]));

        $this->expectException(CredentialsRejectedException::class);
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

    public function testAForbiddenAnswerIsAlsoARejectedKey(): void
    {
        $client = $this->clientAnswering(new MockResponse('{"error":"nope"}', ['http_code' => 403]));

        $this->expectException(CredentialsRejectedException::class);
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
        (new OpenAiCompatibleChatClient($client, 'SimpleFeedReader/1.0'))
            ->complete($this->credentials(), 'm', $this->messages());
    }
}
