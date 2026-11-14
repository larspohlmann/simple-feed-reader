<?php

declare(strict_types=1);

namespace App\Tests\Service\Recommendation\Scoring\RerankClient;

use App\Service\Ai\Exception\ProviderRejectedRequestException;
use App\Service\Ai\Model\ProviderCredentialsModel;
use App\Service\Recommendation\Scoring\Model\RerankRequestModel;
use App\Service\Recommendation\Scoring\Model\ScoringOutcomeModel;
use App\Service\Recommendation\Scoring\RerankClient\HttpRerankClient;
use App\Service\Recommendation\Scoring\ScoringHttpTransport;
use App\Tests\Support\CountingProviderCallHeartbeat;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/** The shared status mapping and streaming are HttpSystemOneClientTest's; this pins what Rerank adds. */
final class HttpRerankClientTest extends TestCase
{
    public function testItPostsTheRequestAsJsonToTheRerankEndpointWithTheKey(): void
    {
        $response = new MockResponse('{"results":[]}');

        $this->rerank([$response], $this->request([7 => 'Kernel 6.18 — LWN, 2026-10-01.']));

        self::assertSame('POST', $response->getRequestMethod());
        self::assertSame('https://openrouter.test/api/v1/rerank', $response->getRequestUrl());
        /** @var array{headers: list<string>, body: string} $options */
        $options = $response->getRequestOptions();
        self::assertContains('Authorization: Bearer sk-or', $options['headers']);
        self::assertSame(
            '{"model":"cohere/rerank-4-fast","query":"Likes Rust.","documents":["Kernel 6.18 — LWN, 2026-10-01."]}',
            $options['body'],
        );
    }

    /** Both replies say index 0; only the request each answers says which entry that is. */
    public function testEachReplyIsReadAgainstItsOwnRequestsDocuments(): void
    {
        $outcomes = $this->rerank(
            [
                new MockResponse(
                    '{"id":"gen-rerank-a","model":"cohere/rerank-4-fast-20260901","results":['
                    . '{"index":1,"relevance_score":0.874},{"index":0,"relevance_score":0.0731}],'
                    . '"usage":{"search_units":1,"cost":0.002}}',
                ),
                new MockResponse('{"results":[{"index":0,"relevance_score":0.5}]}'),
            ],
            $this->request([7 => 'Soup — Kitchen, 2026-10-02.', 9 => 'Postgres 19 — LWN, 2026-10-01.']),
            $this->request([11 => 'Kernel 6.18 — LWN, 2026-10-01.']),
        );

        self::assertSame([9 => 0.874, 7 => 0.0731], $outcomes[0]->reply()->scores);
        self::assertSame('gen-rerank-a', $outcomes[0]->reply()->receipt->requestId);
        self::assertSame('cohere/rerank-4-fast-20260901', $outcomes[0]->reply()->receipt->answeringModel);
        self::assertSame([11 => 0.5], $outcomes[1]->reply()->scores);
    }

    public function testA429IsRetryableAfterTheWaitItNames(): void
    {
        $outcome = $this->rerank(
            [new MockResponse('{}', ['http_code' => 429, 'response_headers' => ['retry-after' => '17']])],
            $this->request([7 => 'Soup']),
        )[0];

        self::assertTrue($outcome->isRetryable());
        self::assertSame(17, $outcome->retryAfterSeconds());
    }

    public function testAnOverloaded529IsRetryable(): void
    {
        $outcome = $this->rerank(
            [new MockResponse('{}', ['http_code' => 529])],
            $this->request([7 => 'Soup']),
        )[0];

        self::assertTrue($outcome->isRetryable());
    }

    /** OpenRouter forwards the upstream's validation failure as 422, the upstream body inside `error.message`. */
    public function testAForwardedRefusalNamesWhatTheUpstreamObjectedTo(): void
    {
        $outcome = $this->rerank(
            [new MockResponse(
                '{"error":{"message":"{\"message\":\"too many documents\"}","code":422}}',
                ['http_code' => 422],
            )],
            $this->request([7 => 'Soup']),
        )[0];

        $refusal = $outcome->cause();
        self::assertInstanceOf(ProviderRejectedRequestException::class, $refusal);
        self::assertSame(
            'That provider refused the request (status 422): {"message":"too many documents"}',
            $refusal->getMessage(),
        );
    }

    /**
     * @param list<MockResponse> $responses
     *
     * @return list<ScoringOutcomeModel>
     */
    private function rerank(array $responses, RerankRequestModel $request, RerankRequestModel ...$siblings): array
    {
        $client = new HttpRerankClient(new ScoringHttpTransport(
            new MockHttpClient($responses),
            new CountingProviderCallHeartbeat(),
            new MockClock(),
            'SimpleFeedReader/1.0',
        ));

        return $client->rerankMany(
            ProviderCredentialsModel::fromStoredConfiguration('https://openrouter.test/api/v1', 'sk-or'),
            [$request, ...array_values($siblings)],
        );
    }

    /** @param non-empty-array<int, string> $documents */
    private function request(array $documents): RerankRequestModel
    {
        return new RerankRequestModel('cohere/rerank-4-fast', 'Likes Rust.', $documents);
    }
}
