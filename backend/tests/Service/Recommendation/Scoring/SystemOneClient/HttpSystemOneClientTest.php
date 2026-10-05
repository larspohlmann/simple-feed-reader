<?php

declare(strict_types=1);

namespace App\Tests\Service\Recommendation\Scoring\SystemOneClient;

use App\Service\Ai\Exception\CredentialsRejectedException;
use App\Service\Ai\Exception\ProviderRejectedRequestException;
use App\Service\Ai\Exception\ProviderUnreachableException;
use App\Service\Ai\Model\ProviderCredentialsModel;
use App\Service\Recommendation\Run\ProviderCallHeartbeat\ProviderCallHeartbeatInterface;
use App\Service\Recommendation\Scoring\Model\ScoringOutcomeModel;
use App\Service\Recommendation\Scoring\Model\SystemOneRequestModel;
use App\Service\Recommendation\Scoring\ScoringHttpTransport;
use App\Service\Recommendation\Scoring\SystemOneClient\HttpSystemOneClient;
use App\Tests\Support\CountingProviderCallHeartbeat;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\Exception\TransportException;
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
            '{"model":"jev-latest","state":{"guidance":"Rüstzeug für Rust"},'
            . '"questions":{"entry-7":{"type":"noul","instructions":{"question":"Read `article`?"}}}}',
            $options['body'],
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
        self::assertSame([7 => 0.95, 9 => 0.125], $reply->scores);
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
        yield 'a request timeout, no verdict on the request' => [
            408,
            ProviderUnreachableException::class,
            'That provider answered with status 408.',
        ];
        yield 'a redirect, never followed' => [
            300,
            ProviderUnreachableException::class,
            'That provider answered with status 300.',
        ];
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

    /** @return iterable<string, array{int, string, string}> */
    public static function refusedRequests(): iterable
    {
        yield 'OpenRouter, an unknown model' => [
            400,
            '{"error":{"message":"Model typesafe/jev-preview does not exist","code":400},"user_id":"user_2xYz"}',
            'That provider refused the request (status 400): Model typesafe/jev-preview does not exist',
        ];
        yield 'OpenRouter, a malformed question' => [
            400,
            '{"error":{"message":"[{\\"code\\":\\"invalid_union\\",\\"path\\":[\\"questions\\",\\"entry-1\\",'
            . '\\"instructions\\"]}]","code":400},"user_id":"user_2xYz"}',
            'That provider refused the request (status 400): [{"code":"invalid_union","path":["questions","entry-1",'
            . '"instructions"]}]',
        ];
        yield 'TypeSafe, a field list' => [
            422,
            '{"detail":[{"loc":["body","questions","entry-7","type"],"msg":"Input should be \'noul\'"}]}',
            'That provider refused the request (status 422): [{"loc":["body","questions","entry-7","type"],'
            . '"msg":"Input should be \'noul\'"}]',
        ];
        yield 'a detail string' => [
            422,
            '{"detail":"state too long"}',
            'That provider refused the request (status 422): state too long',
        ];
        yield 'a route that does not exist' => [
            404,
            '{"detail":"Not Found"}',
            'That provider refused the request (status 404): Not Found',
        ];
        yield 'a proxy page, never echoed' => [
            400,
            '<html lang="en"><body>Bad Request: user_2xYz</body></html>',
            'That provider refused the request (status 400).',
        ];
        yield 'an error without a message' => [
            400,
            '{"error":"Bad Request","user_id":"user_2xYz"}',
            'That provider refused the request (status 400).',
        ];
    }

    #[DataProvider('refusedRequests')]
    public function testARefusedRequestNamesWhatTheProviderObjectedToAndNeverEchoesTheBody(
        int $status,
        string $body,
        string $message,
    ): void {
        $outcome = $this->evaluate(
            [new MockResponse($body, ['http_code' => $status])],
            $this->request('entry-7'),
        )[0];

        $refusal = $outcome->cause();
        self::assertInstanceOf(ProviderRejectedRequestException::class, $refusal);
        self::assertSame($status, $refusal->status());
        self::assertSame($message, $refusal->getMessage());
        self::assertFalse($outcome->isRetryable());
    }

    public function testARefusalThatRepeatsTheApiKeyShowsItRedacted(): void
    {
        $outcome = $this->evaluate(
            [new MockResponse('{"detail":"Bad key sk-jev in header"}', ['http_code' => 400])],
            $this->request('entry-7'),
        )[0];

        self::assertSame(
            'That provider refused the request (status 400): Bad key [redacted] in header',
            $outcome->cause()->getMessage(),
        );
    }

    /** The error lands in a utf8mb4 column under MySQL strict mode, where an invalid byte fails the whole tick. */
    public function testAnInvalidByteInARefusalStillYieldsValidText(): void
    {
        $outcome = $this->evaluate(
            [new MockResponse("{\"detail\":\"Feld \xC3 fehlt\"}", ['http_code' => 422])],
            $this->request('entry-7'),
        )[0];

        self::assertSame(
            "That provider refused the request (status 422): Feld \u{FFFD} fehlt",
            $outcome->cause()->getMessage(),
        );
    }

    public function testALongRefusalIsClippedToFiveHundredCharactersWithoutSplittingOne(): void
    {
        $outcome = $this->evaluate(
            [new MockResponse(
                '{"error":{"message":"' . str_repeat('ä', 600) . '","code":400}}',
                ['http_code' => 400],
            )],
            $this->request('entry-7'),
        )[0];

        self::assertSame(
            'That provider refused the request (status 400): ' . str_repeat('ä', 500) . '…',
            $outcome->cause()->getMessage(),
        );
    }

    public function testAnAnswerOverOneMebibyteIsThatCallsFailure(): void
    {
        $outcome = $this->evaluate(
            [new MockResponse(str_repeat(' ', 1_048_577) . '{"answers":{}}')],
            $this->request('entry-7'),
        )[0];

        self::assertSame('That address did not answer.', $outcome->cause()->getMessage());
        self::assertSame(
            'That provider answered with more than 1048576 bytes.',
            $outcome->cause()->getPrevious()?->getMessage(),
        );
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

        self::assertCount(2, $outcomes);
        self::assertSame('That address did not answer.', $outcomes[0]->cause()->getMessage());
        self::assertSame([9 => 0.3], $outcomes[1]->reply()->scores);
    }

    public function testARequestThatCannotEvenBeSentIsItsOwnCallsOutcomeAndSparesItsSibling(): void
    {
        $outcomes = $this->evaluate(
            [
                new MockResponse('{"answers":{"entry-7":{"type":"noul","noul":0.7}}}'),
                static fn (): never => throw new TransportException('Could not resolve host: api.typesafe.test'),
            ],
            $this->request('entry-7'),
            $this->request('entry-9'),
        );

        self::assertSame([7 => 0.7], $outcomes[0]->reply()->scores);
        self::assertSame('That address did not answer.', $outcomes[1]->cause()->getMessage());
        self::assertSame(
            'Could not resolve host: api.typesafe.test',
            $outcomes[1]->cause()->getPrevious()?->getMessage(),
        );
    }

    /** The sweep goes on past a response that is still speaking: a silent one behind it fails all the same. */
    public function testASilentResponseBehindOneStillSpeakingFails(): void
    {
        $outcomes = $this->evaluateWhileTheClockRuns(
            [
                new MockResponse(['{"answers":', ' ', ' ', ' ', ' ', '{"entry-7":{"type":"noul","noul":0.6}}}']),
                new MockResponse(['', '', '', '', '{"answers":{"entry-9":{"type":"noul","noul":0.3}}}']),
            ],
            $this->request('entry-7'),
            $this->request('entry-9'),
        );

        self::assertSame([7 => 0.6], $outcomes[0]->reply()->scores);
        self::assertSame('That provider sent nothing for more than 120 seconds.', $outcomes[1]->cause()->getMessage());
    }

    public function testTheWaitBeatsTheTicksHeartbeat(): void
    {
        $heartbeat = new CountingProviderCallHeartbeat();
        $client = new HttpSystemOneClient(new ScoringHttpTransport(
            new MockHttpClient([new MockResponse('{"answers":{}}')]),
            $heartbeat,
            new MockClock(),
            'SimpleFeedReader/1.0',
        ));

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
        self::assertSame([9 => 0.3], $outcomes[1]->reply()->scores);
    }

    /** 305 s in all, yet never 120 s without a chunk: every chunk the provider sends restarts the idle bound. */
    public function testAProviderThatKeepsSendingIsNeverIdle(): void
    {
        $outcomes = $this->evaluateWhileTheClockRuns(
            [new MockResponse(['{"answers":', '', '{"entry-7":{"type":"noul","noul":0.6}}}'])],
            $this->request('entry-7'),
        );

        self::assertSame([7 => 0.6], $outcomes[0]->reply()->scores);
    }

    /**
     * @param list<MockResponse> $responses
     *
     * @return list<ScoringOutcomeModel>
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
        $client = new HttpSystemOneClient(new ScoringHttpTransport(
            new MockHttpClient($responses),
            $heartbeat,
            $clock,
            'SimpleFeedReader/1.0',
        ));

        return $client->evaluateMany($this->credentials(), [$request, ...array_values($siblings)]);
    }

    /**
     * @param list<MockResponse|\Closure(): never> $responses
     *
     * @return list<ScoringOutcomeModel>
     */
    private function evaluate(
        array $responses,
        SystemOneRequestModel $request,
        SystemOneRequestModel ...$siblings,
    ): array {
        $client = new HttpSystemOneClient(new ScoringHttpTransport(
            new MockHttpClient($responses),
            new CountingProviderCallHeartbeat(),
            new MockClock(),
            'SimpleFeedReader/1.0',
        ));

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
