<?php

declare(strict_types=1);

namespace App\Tests\Service\Recommendation\Scoring;

use App\Service\Ai\Model\ProviderCallReceiptModel;
use App\Service\Ai\Model\ProviderCredentialsModel;
use App\Service\Fetch\Support\ResponseHeader;
use App\Service\Recommendation\Scoring\Model\ScoringReplyModel;
use App\Service\Recommendation\Scoring\Pass\ScoringEndpoint;
use App\Service\Recommendation\Scoring\ScoringHttpTransport;
use App\Tests\Support\CountingProviderCallHeartbeat;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\ResponseInterface;

/** The endpoint is the protocol's; everything else is the transport's, and HttpSystemOneClientTest covers it. */
final class ScoringHttpTransportTest extends TestCase
{
    public function testItPostsEachBodyToTheEndpointsPathAndReadsTheReplyTheEndpointsWay(): void
    {
        $response = new MockResponse('{"ranked":[0.4]}', ['response_headers' => ['x-rank-request' => 'rank-31']]);

        $outcomes = $this->transport([$response])->sendAll(
            new ScoringEndpoint(
                '/rank',
                [],
                static fn (string $body, ResponseInterface $reply): ScoringReplyModel => new ScoringReplyModel(
                    $body,
                    [31 => 0.4],
                    new ProviderCallReceiptModel(ResponseHeader::first($reply, 'x-rank-request'), null, null),
                ),
            ),
            $this->credentials(),
            ['{"query":"Rust"}'],
        );

        self::assertSame('https://api.rank.test/v2/rank', $response->getRequestUrl());
        /** @var array{headers: list<string>, body: string} $options */
        $options = $response->getRequestOptions();
        self::assertSame('{"query":"Rust"}', $options['body']);
        self::assertContains('Authorization: Bearer sk-rank', $options['headers']);
        self::assertSame([31 => 0.4], $outcomes[0]->reply()->scores);
        self::assertSame('rank-31', $outcomes[0]->reply()->receipt->requestId);
    }

    public function testItSendsEachRequestWithoutRedirectsOrCompressionAndIdentifiesItself(): void
    {
        /** @var array{max_redirects: int, normalized_headers: array<string, list<string>>} $captured */
        $captured = ['max_redirects' => -1, 'normalized_headers' => []];
        $httpClient = new MockHttpClient(
            static function (string $method, string $url, array $options) use (&$captured): MockResponse {
                /** @var array{max_redirects: int, normalized_headers: array<string, list<string>>} $options */
                $captured = $options;

                return new MockResponse('{}');
            },
        );

        (new ScoringHttpTransport(
            $httpClient,
            new CountingProviderCallHeartbeat(),
            new MockClock(),
            'SimpleFeedReader/1.0',
        ))->sendAll(
            new ScoringEndpoint('/rank', [], static fn (): ScoringReplyModel => new ScoringReplyModel(
                '{}',
                [],
                new ProviderCallReceiptModel(null, null, null),
            )),
            $this->credentials(),
            ['{}'],
        );

        self::assertSame(0, $captured['max_redirects']);
        self::assertContains('Accept: application/json', $captured['normalized_headers']['accept']);
        self::assertContains('Accept-Encoding: identity', $captured['normalized_headers']['accept-encoding']);
        self::assertContains('User-Agent: SimpleFeedReader/1.0', $captured['normalized_headers']['user-agent']);
    }

    /** 529 is System One's own: an endpoint that does not name it gets the shared mapping. */
    public function testOnlyTheEndpointsOwnStatusesAreRetryable(): void
    {
        $outcomes = $this->transport([
            new MockResponse('{}', ['http_code' => 503]),
            new MockResponse('{}', ['http_code' => 529]),
        ])->sendAll(
            new ScoringEndpoint(
                '/rank',
                [503],
                static fn (): ScoringReplyModel => throw new \LogicException('No reply was expected.'),
            ),
            $this->credentials(),
            ['{}', '{}'],
        );

        self::assertTrue($outcomes[0]->isRetryable());
        self::assertFalse($outcomes[1]->isRetryable());
        self::assertSame('That provider answered with status 529.', $outcomes[1]->cause()->getMessage());
    }

    /** @param list<MockResponse> $responses */
    private function transport(array $responses): ScoringHttpTransport
    {
        return new ScoringHttpTransport(
            new MockHttpClient($responses),
            new CountingProviderCallHeartbeat(),
            new MockClock(),
            'SimpleFeedReader/1.0',
        );
    }

    private function credentials(): ProviderCredentialsModel
    {
        return ProviderCredentialsModel::fromStoredConfiguration('https://api.rank.test/v2', 'sk-rank');
    }
}
