<?php

declare(strict_types=1);

namespace App\Tests\Service\Ai;

use App\Service\Ai\Exception\CredentialsRejectedException;
use App\Service\Ai\Exception\ProviderUnreachableException;
use App\Service\Ai\OpenAiCompatibleCatalog;
use App\Service\Ai\ProviderCredentials;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class OpenAiCompatibleCatalogTest extends TestCase
{
    private function credentials(): ProviderCredentials
    {
        return new ProviderCredentials('https://api.example.test/v1', 'sk-test');
    }

    private function catalogAnswering(MockResponse $response): OpenAiCompatibleCatalog
    {
        return new OpenAiCompatibleCatalog(new MockHttpClient($response), 'SimpleFeedReader/1.0');
    }

    public function testItReturnsTheOfferedModelsSorted(): void
    {
        $catalog = $this->catalogAnswering(new MockResponse(
            '{"data":[{"id":"gpt-4o-mini"},{"id":"claude-sonnet"},{"id":"gpt-4o"}]}',
            ['response_headers' => ['content-type' => 'application/json']],
        ));

        self::assertSame(
            ['claude-sonnet', 'gpt-4o', 'gpt-4o-mini'],
            $catalog->listModels($this->credentials()),
        );
    }

    /**
     * An aggregating proxy in front of several backends lists the same model
     * once per backend. The frontend tracks its dropdown options by the
     * identifier, so a repeat reaching it breaks the rendering.
     */
    public function testItReturnsAnIdentifierOnlyOnce(): void
    {
        $catalog = $this->catalogAnswering(new MockResponse(
            '{"data":[{"id":"gpt-4o"},{"id":"claude-sonnet"},{"id":"gpt-4o"},{"id":"gpt-4o"}]}',
            ['response_headers' => ['content-type' => 'application/json']],
        ));

        self::assertSame(
            ['claude-sonnet', 'gpt-4o'],
            $catalog->listModels($this->credentials()),
        );
    }

    public function testItSendsTheKeyAsABearerToken(): void
    {
        $seen = [];
        $client = new MockHttpClient(function (string $method, string $url, array $options) use (&$seen): MockResponse {
            $seen = ['method' => $method, 'url' => $url, 'headers' => $options['headers'] ?? []];

            return new MockResponse('{"data":[{"id":"gpt-4o"}]}');
        });

        (new OpenAiCompatibleCatalog($client, 'SimpleFeedReader/1.0'))->listModels($this->credentials());

        /** @var array{method: string, url: string, headers: array<int, string>} $seen */
        self::assertSame('GET', $seen['method']);
        self::assertSame('https://api.example.test/v1/models', $seen['url']);
        self::assertContains('Authorization: Bearer sk-test', $seen['headers']);
    }

    public function testItRefusesTransparentCompression(): void
    {
        $seen = [];
        $client = new MockHttpClient(function (string $method, string $url, array $options) use (&$seen): MockResponse {
            $seen = ['headers' => $options['headers'] ?? []];

            return new MockResponse('{"data":[{"id":"gpt-4o"}]}');
        });

        (new OpenAiCompatibleCatalog($client, 'SimpleFeedReader/1.0'))->listModels($this->credentials());

        /** @var array{headers: array<int, string>} $seen */
        self::assertContains('Accept-Encoding: identity', $seen['headers']);
    }

    public function testAnOversizedBodyIsUnreachable(): void
    {
        // This is otherwise-valid JSON that would decode into one perfectly good
        // model — the id is just padded well past MAXIMUM_RESPONSE_BYTES. The cap
        // truncating the stream mid-string is the ONLY reason this throws: without
        // it, this body parses and listModels() returns successfully.
        //
        // Split into small chunks: MockHttpClient delivers one plain string as a
        // single chunk, which would let it land in $content whole before the size
        // check ever runs. Chunking matches how a real response actually streams
        // and is what makes this test able to catch a cap that stopped truncating.
        $body = '{"data":[{"id":"' . str_repeat('a', 2_000_000) . '"}]}';
        $catalog = $this->catalogAnswering(new MockResponse(str_split($body, 50_000)));

        $this->expectException(ProviderUnreachableException::class);
        $catalog->listModels($this->credentials());
    }

    public function testARejectedKeyIsDistinguishedFromAnUnreachableProvider(): void
    {
        $catalog = $this->catalogAnswering(new MockResponse('{"error":"nope"}', ['http_code' => 401]));

        $this->expectException(CredentialsRejectedException::class);
        $catalog->listModels($this->credentials());
    }

    public function testAForbiddenAnswerIsAlsoARejectedKey(): void
    {
        $catalog = $this->catalogAnswering(new MockResponse('{"error":"nope"}', ['http_code' => 403]));

        $this->expectException(CredentialsRejectedException::class);
        $catalog->listModels($this->credentials());
    }

    public function testAServerErrorIsUnreachable(): void
    {
        $catalog = $this->catalogAnswering(new MockResponse('', ['http_code' => 500]));

        $this->expectException(ProviderUnreachableException::class);
        $catalog->listModels($this->credentials());
    }

    public function testATransportFailureIsUnreachable(): void
    {
        $client = new MockHttpClient(static function (): MockResponse {
            throw new TransportException('Connection refused');
        });

        $this->expectException(ProviderUnreachableException::class);
        (new OpenAiCompatibleCatalog($client, 'SimpleFeedReader/1.0'))->listModels($this->credentials());
    }

    public function testMalformedJsonIsUnreachable(): void
    {
        /** @noinspection HtmlRequiredLangAttribute this is a stub answer, not a real document */
        $catalog = $this->catalogAnswering(new MockResponse('<html>nope</html>'));

        $this->expectException(ProviderUnreachableException::class);
        $catalog->listModels($this->credentials());
    }

    public function testAnEmptyModelListIsUnreachable(): void
    {
        $catalog = $this->catalogAnswering(new MockResponse('{"data":[]}'));

        $this->expectException(ProviderUnreachableException::class);
        $catalog->listModels($this->credentials());
    }

    public function testEntriesWithoutAnIdAreIgnored(): void
    {
        $catalog = $this->catalogAnswering(new MockResponse('{"data":[{"id":"gpt-4o"},{"object":"model"}]}'));

        self::assertSame(['gpt-4o'], $catalog->listModels($this->credentials()));
    }

    public function testTheBaseUrlLosesItsTrailingSlash(): void
    {
        self::assertSame(
            'https://api.example.test/v1',
            ProviderCredentials::normalizeBaseUrl('  https://api.example.test/v1//  '),
        );
    }

    public function testALocalProviderIsAccepted(): void
    {
        self::assertSame(
            'http://localhost:11434/v1',
            ProviderCredentials::normalizeBaseUrl('http://localhost:11434/v1'),
        );
    }

    public function testANonHttpSchemeIsRefused(): void
    {
        $this->expectException(ProviderUnreachableException::class);
        ProviderCredentials::normalizeBaseUrl('file:///etc/passwd');
    }

    public function testCredentialsInTheUrlAreRefused(): void
    {
        $this->expectException(ProviderUnreachableException::class);
        ProviderCredentials::normalizeBaseUrl('https://user:pass@api.example.test/v1');
    }

    public function testAQueryStringInTheUrlIsRefused(): void
    {
        $this->expectException(ProviderUnreachableException::class);
        ProviderCredentials::normalizeBaseUrl('https://api.example.test/v1?tenant=1');
    }

    public function testAFragmentInTheUrlIsRefused(): void
    {
        $this->expectException(ProviderUnreachableException::class);
        ProviderCredentials::normalizeBaseUrl('https://api.example.test/v1#section');
    }
}
