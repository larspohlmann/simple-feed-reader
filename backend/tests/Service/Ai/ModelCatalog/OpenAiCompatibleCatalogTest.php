<?php

declare(strict_types=1);

namespace App\Tests\Service\Ai\ModelCatalog;

use App\Entity\ModelDescriptor;
use App\Enum\ScoringProtocol;
use App\Service\Ai\Exception\CredentialsRejectedException;
use App\Service\Ai\Exception\ProviderUnreachableException;
use App\Service\Ai\Model\ProviderCredentialsModel;
use App\Service\Ai\ModelCatalog\OpenAiCompatibleCatalog;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class OpenAiCompatibleCatalogTest extends TestCase
{
    private function credentials(): ProviderCredentialsModel
    {
        return ProviderCredentialsModel::fromStoredConfiguration('https://api.example.test/v1', 'sk-test');
    }

    private function catalogAnswering(MockResponse $response): OpenAiCompatibleCatalog
    {
        return new OpenAiCompatibleCatalog(new MockHttpClient($response), 'SimpleFeedReader/1.0');
    }

    /**
     * @param list<ModelDescriptor> $models
     *
     * @return list<string>
     */
    private function ids(array $models): array
    {
        return array_map(static fn (ModelDescriptor $model): string => $model->id, $models);
    }

    public function testItReturnsTheOfferedModelsSorted(): void
    {
        $catalog = $this->catalogAnswering(new MockResponse(
            '{"data":[{"id":"gpt-4o-mini"},{"id":"claude-sonnet"},{"id":"gpt-4o"}]}',
            ['response_headers' => ['content-type' => 'application/json']],
        ));

        self::assertSame(
            ['claude-sonnet', 'gpt-4o', 'gpt-4o-mini'],
            $this->ids($catalog->listModels($this->credentials())),
        );
    }

    /** @return iterable<string, array{array<string, mixed>, ?ModelDescriptor}> */
    public static function listedEntries(): iterable
    {
        yield 'no architecture (LM Studio, OpenAI)' => [
            ['id' => 'qwen3-14b', 'context_length' => 32_768],
            new ModelDescriptor('qwen3-14b', 32_768),
        ];
        yield 'a text model' => [
            self::entry('inclusionai/ling-3.1-flash', 262_144, ['text']),
            new ModelDescriptor('inclusionai/ling-3.1-flash', 262_144),
        ];
        yield 'a model that writes images beside text' => [
            self::entry('google/gemini-3.1-flash-lite-image', 65_536, ['image', 'text']),
            new ModelDescriptor('google/gemini-3.1-flash-lite-image', 65_536),
        ];
        yield 'a decision model' => [
            self::entry('~typesafe/jev-latest', 32_000, ['decisions']),
            new ModelDescriptor('~typesafe/jev-latest', 32_000, ScoringProtocol::SystemOne),
        ];
        yield 'a decision model without a window (Respan)' => [
            self::entry('respan/span-01', 0, ['decisions']),
            null,
        ];
        yield 'a reranker, until its protocol exists' => [
            self::entry('cohere/rerank-4-fast', 32_768, ['rerank']),
            null,
        ];
        yield 'an image model' => [self::entry('bytedance-seed/seedream-5-0-flash', 0, ['image']), null];
        yield 'an embedding model' => [self::entry('liquid/lfm-2.5-embedding-350m:free', 512, ['embeddings']), null];
        yield 'decisions beside another output' => [self::entry('acme/mixed-1', 32_000, ['decisions', 'rerank']), null];
        yield 'no output at all' => [self::entry('acme/silent-1', 32_000, []), null];
        yield 'an architecture that is no object' => [
            ['id' => 'acme/plain-1', 'context_length' => 32_000, 'architecture' => 'text'],
            new ModelDescriptor('acme/plain-1', 32_000),
        ];
        yield 'outputs keyed instead of listed' => [
            [
                'id' => 'acme/keyed-1',
                'context_length' => 32_000,
                'architecture' => ['output_modalities' => ['first' => 'decisions']],
            ],
            new ModelDescriptor('acme/keyed-1', 32_000, ScoringProtocol::SystemOne),
        ];
        yield 'a single output that is no string' => [self::entry('acme/numeric-1', 32_000, [42]), null];
    }

    /**
     * A second, plain entry keeps the listing from coming back empty when the entry under test is left out.
     *
     * @param array<string, mixed> $entry
     */
    #[DataProvider('listedEntries')]
    public function testTheListedOutputsDecideWhatAModelIs(array $entry, ?ModelDescriptor $expected): void
    {
        $catalog = $this->catalogAnswering(new MockResponse(json_encode(
            ['data' => [$entry, ['id' => 'anchor-model']]],
            JSON_THROW_ON_ERROR,
        )));

        $models = array_values(array_filter(
            $catalog->listModels($this->credentials()),
            static fn (ModelDescriptor $model): bool => 'anchor-model' !== $model->id,
        ));

        self::assertEquals(null === $expected ? [] : [$expected], $models);
    }

    /**
     * @param list<mixed> $outputs
     *
     * @return array<string, mixed>
     */
    private static function entry(string $id, int $contextLength, array $outputs): array
    {
        return ['id' => $id, 'context_length' => $contextLength, 'architecture' => ['output_modalities' => $outputs]];
    }

    public function testCapturesContextLengthWhenTheProviderReportsOne(): void
    {
        $catalog = $this->catalogAnswering(new MockResponse(json_encode(['data' => [
            ['id' => 'small', 'context_length' => 8192],
            ['id' => 'big', 'max_context_length' => 200000],
            ['id' => 'silent'],
        ]], JSON_THROW_ON_ERROR)));

        $models = $catalog->listModels($this->credentials());

        self::assertSame(['big', 'silent', 'small'], $this->ids($models));
        self::assertSame([200000, null, 8192], array_map(static fn ($model) => $model->contextWindow, $models));
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
            $this->ids($catalog->listModels($this->credentials())),
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
        self::assertSame('https://api.example.test/v1/models?output_modalities=all', $seen['url']);
        self::assertContains('Authorization: Bearer sk-test', $seen['headers']);
    }

    /**
     * A keyless credential (a local model server) must not send `Bearer ` with
     * nothing after it — ProviderCredentialsModel::authorizationHeaders() drops the
     * header entirely rather than sending a malformed one.
     */
    public function testAKeylessCredentialSendsNoAuthorizationHeader(): void
    {
        $seen = [];
        $client = new MockHttpClient(function (string $method, string $url, array $options) use (&$seen): MockResponse {
            $seen = ['headers' => $options['headers'] ?? []];

            return new MockResponse('{"data":[{"id":"gpt-4o"}]}');
        });

        $credentials = ProviderCredentialsModel::fromStoredConfiguration('https://api.example.test/v1', '');
        (new OpenAiCompatibleCatalog($client, 'SimpleFeedReader/1.0'))->listModels($credentials);

        /** @var array{headers: array<int, string>} $seen */
        $authorizationHeaders = array_filter(
            $seen['headers'],
            static fn (string $header): bool => str_starts_with($header, 'Authorization:'),
        );
        self::assertSame([], $authorizationHeaders);
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

    public function testABodyPastTheOldOneMebibyteCapIsStillRead(): void
    {
        // OpenRouter's all-modalities listing alone is about 1 MB; chunked, so the wire cap sees its progress.
        $body = '{"data":[{"id":"' . str_repeat('a', 2_000_000) . '"}]}';
        $catalog = $this->catalogAnswering(new MockResponse(str_split($body, 50_000)));

        self::assertCount(1, $catalog->listModels($this->credentials()));
    }

    public function testAnOversizedBodyIsUnreachable(): void
    {
        // Valid JSON padded past MAXIMUM_RESPONSE_BYTES: only the wire cap refuses it. Chunked, because MockHttpClient
        // reports a single string's progress only once it is complete.
        $body = '{"data":[{"id":"' . str_repeat('a', 4_500_000) . '"}]}';
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
        $this->expectExceptionMessage('That provider answered with status 500.');
        $catalog->listModels($this->credentials());
    }

    public function testATransportFailureIsUnreachable(): void
    {
        $client = new MockHttpClient(static function (): MockResponse {
            throw new TransportException('Connection refused');
        });

        $this->expectException(ProviderUnreachableException::class);
        $this->expectExceptionMessage('That address did not answer.');
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

        self::assertSame(['gpt-4o'], $this->ids($catalog->listModels($this->credentials())));
    }

    /**
     * The invalid entry (a non-string id) comes first, on purpose: it proves
     * the loop skips past it rather than stopping there, which a plain id
     * check alone would not.
     */
    public function testANonStringIdIsIgnoredWithoutStoppingLaterEntries(): void
    {
        $catalog = $this->catalogAnswering(new MockResponse('{"data":[{"id":42},{"id":"gpt-4o"}]}'));

        self::assertSame(['gpt-4o'], $this->ids($catalog->listModels($this->credentials())));
    }

    /**
     * An aggregating proxy repeats the same id once per backend, and those
     * backends can disagree about the model's context window. The first one
     * seen wins, so a later repeat cannot silently overwrite it.
     */
    public function testTheFirstReportedContextWindowForARepeatedIdWins(): void
    {
        $catalog = $this->catalogAnswering(new MockResponse(json_encode(['data' => [
            ['id' => 'gpt-4o', 'context_length' => 8192],
            ['id' => 'gpt-4o', 'context_length' => 4096],
        ]], JSON_THROW_ON_ERROR)));

        $models = $catalog->listModels($this->credentials());

        self::assertSame([8192], array_map(static fn ($model) => $model->contextWindow, $models));
    }

    /**
     * Zero is not a context window a real provider would report — it exists
     * only to prove the comparison is a strict ">", not ">=", boundary.
     */
    public function testAZeroReportedContextLengthIsTreatedAsNotReported(): void
    {
        $catalog = $this->catalogAnswering(new MockResponse(
            '{"data":[{"id":"gpt-4o","context_length":0}]}',
        ));

        $models = $catalog->listModels($this->credentials());

        self::assertNull($models[0]->contextWindow);
    }

    public function testTheBaseUrlLosesItsTrailingSlash(): void
    {
        self::assertSame(
            'https://api.example.test/v1',
            ProviderCredentialsModel::fromAccountInput('  https://api.example.test/v1//  ', 'sk-test')->baseUrl,
        );
    }

    public function testTheApiKeyLosesItsSurroundingSpace(): void
    {
        self::assertSame(
            'sk-test',
            ProviderCredentialsModel::fromAccountInput('https://api.example.test/v1', "  sk-test\n")->apiKey,
        );
    }

    public function testALocalProviderIsAccepted(): void
    {
        self::assertSame(
            'http://localhost:11434/v1',
            ProviderCredentialsModel::fromAccountInput('http://localhost:11434/v1', 'sk-test')->baseUrl,
        );
    }

    public function testANonHttpSchemeIsRefused(): void
    {
        $this->expectException(ProviderUnreachableException::class);
        ProviderCredentialsModel::fromAccountInput('file:///etc/passwd', 'sk-test');
    }

    public function testCredentialsInTheUrlAreRefused(): void
    {
        $this->expectException(ProviderUnreachableException::class);
        ProviderCredentialsModel::fromAccountInput('https://user:pass@api.example.test/v1', 'sk-test');
    }

    public function testAQueryStringInTheUrlIsRefused(): void
    {
        $this->expectException(ProviderUnreachableException::class);
        ProviderCredentialsModel::fromAccountInput('https://api.example.test/v1?tenant=1', 'sk-test');
    }

    public function testAFragmentInTheUrlIsRefused(): void
    {
        $this->expectException(ProviderUnreachableException::class);
        ProviderCredentialsModel::fromAccountInput('https://api.example.test/v1#section', 'sk-test');
    }

    public function testATransportFailureCarriesNoErrorCode(): void
    {
        $client = new MockHttpClient(static function (): MockResponse {
            throw new TransportException('Connection refused');
        });

        try {
            (new OpenAiCompatibleCatalog($client, 'SimpleFeedReader/1.0'))->listModels($this->credentials());
            self::fail(ProviderUnreachableException::class . ' was not thrown.');
        } catch (ProviderUnreachableException $exception) {
            self::assertSame(0, $exception->getCode());
        }
    }
}
