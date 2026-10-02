<?php

declare(strict_types=1);

namespace App\Tests\Service\Recommendation\Jev\SystemOneClient;

use App\Service\Ai\Exception\CredentialsRejectedException;
use App\Service\Ai\Exception\ProviderUnreachableException;
use App\Service\Ai\Model\ProviderCredentialsModel;
use App\Service\Recommendation\Jev\Model\SystemOneOutcomeModel;
use App\Service\Recommendation\Jev\Model\SystemOneRequestModel;
use App\Service\Recommendation\Jev\SystemOneClient\HttpSystemOneClient;
use App\Service\Recommendation\Run\ProviderCallHeartbeat\ProviderCallHeartbeatInterface;
use App\Tests\Support\CountingProviderCallHeartbeat;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class HttpSystemOneClientTest extends TestCase
{
    public function testItPostsTheRequestAsJsonToTheSystemOneEndpointWithTheKey(): void
    {
        $response = new MockResponse('{"model":"jev-1.13.0","answers":{}}');

        $this->evaluate([$response], $this->request('entry-7'));

        self::assertSame('POST', $response->getRequestMethod());
        self::assertSame('https://api.typesafe.test/v1/systemone', $response->getRequestUrl());
        /** @var array{headers: list<string>, body: string} $options */
        $options = $response->getRequestOptions();
        self::assertContains('Authorization: Bearer sk-jev', $options['headers']);
        self::assertSame(
            [
                'model' => 'jev-latest',
                'state' => ['guidance' => 'Rüstzeug für Rust'],
                'questions' => ['entry-7' => ['type' => 'noul', 'instructions' => ['question' => 'Read `article`?']]],
            ],
            json_decode($options['body'], true),
        );
    }

    public function testAnAnswerCarriesEachNoulAndTheRequestIdFromTheHeader(): void
    {
        $outcomes = $this->evaluate(
            [new MockResponse(
                '{"id":"gen-ignored","model":"jev-1.13.0","answers":{"entry-7":{"type":"noul","noul":0.95},'
                . '"entry-9":{"type":"noul","noul":0.125}},"usage":{"input_tokens":296,"output_tokens":20}}',
                ['response_headers' => ['x-typesafe-request-id' => 'req-77']],
            )],
            $this->request('entry-7', 'entry-9'),
        );

        $reply = $outcomes[0]->reply();
        self::assertSame(['entry-7' => 0.95, 'entry-9' => 0.125], $reply->nouls);
        self::assertSame('req-77', $reply->receipt->requestId);
        self::assertSame('jev-1.13.0', $reply->receipt->answeringModel);
        self::assertSame(296, $reply->receipt->usage?->promptTokens);
    }

    /** @return iterable<string, array{int, class-string<\RuntimeException>, string}> */
    public static function failedStatuses(): iterable
    {
        yield 'refused key' => [401, CredentialsRejectedException::class, 'That provider refused the API key.'];
        yield 'forbidden key' => [403, CredentialsRejectedException::class, 'That provider refused the API key.'];
        yield 'server error' => [500, ProviderUnreachableException::class, 'That provider answered with status 500.'];
        yield 'gateway' => [503, ProviderUnreachableException::class, 'That provider answered with status 503.'];
    }

    /** @param class-string<\RuntimeException> $failure */
    #[DataProvider('failedStatuses')]
    public function testAFailedStatusIsThatCallsOutcomeAndNotRetried(
        int $status,
        string $failure,
        string $message,
    ): void {
        $outcome = $this->evaluate([new MockResponse('{}', ['http_code' => $status])], $this->request('entry-7'))[0];

        self::assertInstanceOf($failure, $outcome->cause());
        self::assertSame($message, $outcome->cause()->getMessage());
        self::assertFalse($outcome->isRetryable());
    }

    public function testA429IsRetryableAfterTheWaitItNames(): void
    {
        $outcome = $this->evaluate(
            [new MockResponse('{}', ['http_code' => 429, 'response_headers' => ['retry-after' => '17']])],
            $this->request('entry-7'),
        )[0];

        self::assertTrue($outcome->isRetryable());
        self::assertSame(17, $outcome->retryAfterSeconds());
    }

    public function testAnOverloaded529IsRetryableWithoutAHint(): void
    {
        $outcome = $this->evaluate([new MockResponse('{}', ['http_code' => 529])], $this->request('entry-7'))[0];

        self::assertTrue($outcome->isRetryable());
        self::assertNull($outcome->retryAfterSeconds());
    }

    public function testA422NamesTheFieldThatFailedValidation(): void
    {
        $outcome = $this->evaluate(
            [new MockResponse(
                '{"detail":[{"loc":["body","questions","entry-7","type"],"msg":"Input should be \'noul\'"}]}',
                ['http_code' => 422],
            )],
            $this->request('entry-7'),
        )[0];

        self::assertInstanceOf(ProviderUnreachableException::class, $outcome->cause());
        self::assertStringStartsWith(
            'That provider refused the request (status 422): [{"loc":["body","questions","entry-7","type"]',
            $outcome->cause()->getMessage(),
        );
        self::assertFalse($outcome->isRetryable());
    }

    public function testATransportFailureIsItsOwnCallsOutcomeAndSparesItsSibling(): void
    {
        $outcomes = $this->evaluate(
            [
                new MockResponse('', ['error' => 'Connection reset by peer']),
                new MockResponse('{"model":"jev-1.13.0","answers":{"entry-9":{"type":"noul","noul":0.3}}}'),
            ],
            $this->request('entry-7'),
            $this->request('entry-9'),
        );

        self::assertSame('That address did not answer.', $outcomes[0]->cause()->getMessage());
        self::assertSame(['entry-9' => 0.3], $outcomes[1]->reply()->nouls);
    }

    public function testTheWaitBeatsTheTicksHeartbeat(): void
    {
        $heartbeat = new CountingProviderCallHeartbeat();
        $client = new HttpSystemOneClient(
            new MockHttpClient([new MockResponse('{"answers":{}}')]),
            $heartbeat,
            new MockClock(),
            'SimpleFeedReader/1.0',
        );

        $client->evaluateMany($this->credentials(), [$this->request('entry-7')]);

        self::assertGreaterThan(0, $heartbeat->beats());
    }

    /** Each beat passes 61 s: the third chunk after the headers lands 122 s after the provider last spoke. */
    public function testAResponseSilentForLongerThanTheIdleBoundFailsAndSparesItsSibling(): void
    {
        $outcomes = $this->evaluateWhileTheClockRuns(
            [
                new MockResponse(['', '', '{"answers":{"entry-7":{"type":"noul","noul":0.6}}}']),
                new MockResponse('{"answers":{"entry-9":{"type":"noul","noul":0.3}}}'),
            ],
            $this->request('entry-7'),
            $this->request('entry-9'),
        );

        self::assertSame('That provider sent nothing for more than 120 seconds.', $outcomes[0]->cause()->getMessage());
        self::assertFalse($outcomes[0]->isRetryable());
        self::assertSame(['entry-9' => 0.3], $outcomes[1]->reply()->nouls);
    }

    /** 305 s in all, yet never 120 s without a chunk: every chunk the provider sends restarts the idle bound. */
    public function testAProviderThatKeepsSendingIsNeverIdle(): void
    {
        $outcomes = $this->evaluateWhileTheClockRuns(
            [new MockResponse(['{"answers":', '', '{"entry-7":{"type":"noul","noul":0.6}}}'])],
            $this->request('entry-7'),
        );

        self::assertSame(['entry-7' => 0.6], $outcomes[0]->reply()->nouls);
    }

    /**
     * @param list<MockResponse> $responses
     *
     * @return list<SystemOneOutcomeModel>
     */
    private function evaluateWhileTheClockRuns(
        array $responses,
        SystemOneRequestModel $request,
        SystemOneRequestModel ...$siblings,
    ): array {
        $clock = new MockClock();
        $heartbeat = new readonly class ($clock) implements ProviderCallHeartbeatInterface {
            public function __construct(private MockClock $clock)
            {
            }

            public function beat(): void
            {
                $this->clock->sleep(61);
            }
        };
        $client = new HttpSystemOneClient(new MockHttpClient($responses), $heartbeat, $clock, 'SimpleFeedReader/1.0');

        return $client->evaluateMany($this->credentials(), [$request, ...array_values($siblings)]);
    }

    /**
     * @param list<MockResponse> $responses
     *
     * @return list<SystemOneOutcomeModel>
     */
    private function evaluate(
        array $responses,
        SystemOneRequestModel $request,
        SystemOneRequestModel ...$siblings,
    ): array {
        $client = new HttpSystemOneClient(
            new MockHttpClient($responses),
            new CountingProviderCallHeartbeat(),
            new MockClock(),
            'SimpleFeedReader/1.0',
        );

        return $client->evaluateMany($this->credentials(), [$request, ...array_values($siblings)]);
    }

    private function request(string ...$questionIds): SystemOneRequestModel
    {
        $questions = [];
        foreach ($questionIds as $questionId) {
            $questions[$questionId] = ['type' => 'noul', 'instructions' => ['question' => 'Read `article`?']];
        }

        return new SystemOneRequestModel('jev-latest', ['guidance' => 'Rüstzeug für Rust'], $questions);
    }

    private function credentials(): ProviderCredentialsModel
    {
        return ProviderCredentialsModel::fromStoredConfiguration('https://api.typesafe.test/v1', 'sk-jev');
    }
}
