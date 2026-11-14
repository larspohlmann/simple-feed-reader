<?php

declare(strict_types=1);

namespace App\Tests\Service\Transcript\TranscriptSource;

use App\Service\Fetch\GuardedApiClient;
use App\Service\Fetch\IpValidator;
use App\Service\Fetch\Model\ResponseSizeLimit;
use App\Service\Fetch\UrlGuard;
use App\Service\Transcript\Exception\TranscriptUnreachableException;
use App\Service\Transcript\Exception\TranscriptUnusableException;
use App\Service\Transcript\TranscriptSource\GuardedTranscriptSource;
use App\Tests\Support\FetchWiring;
use App\Tests\Support\StaticDnsResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Response;

final class GuardedTranscriptSourceTest extends TestCase
{
    public function testReturnsTheBodyOfAnAnsweredFile(): void
    {
        $source = new GuardedTranscriptSource($this->client(new MockHttpClient(new MockResponse("WEBVTT\n"))));

        self::assertSame("WEBVTT\n", $source->bodyAt('https://cdn.test/ep.vtt', 10.0));
    }

    #[DataProvider('refusedAnswers')]
    public function testANonTransientErrorAnswerIsUnusable(int $status): void
    {
        $source = new GuardedTranscriptSource($this->client(new MockHttpClient(new MockResponse('', [
            'http_code' => $status,
            'response_headers' => ['Location' => 'https://elsewhere.test/ep.vtt'],
        ]))));

        $this->expectException(TranscriptUnusableException::class);
        $source->bodyAt('https://cdn.test/ep.vtt', 10.0);
    }

    /** @return iterable<string, array{int}> */
    public static function refusedAnswers(): iterable
    {
        yield 'not found' => [Response::HTTP_NOT_FOUND];
        yield 'gone' => [Response::HTTP_GONE];
        yield 'redirect' => [Response::HTTP_MOVED_PERMANENTLY];
    }

    #[DataProvider('transientAnswers')]
    public function testATransientAnswerIsUnreachable(int $status): void
    {
        $source = new GuardedTranscriptSource($this->client(new MockHttpClient(
            new MockResponse('', ['http_code' => $status]),
        )));

        $this->expectException(TranscriptUnreachableException::class);
        $source->bodyAt('https://cdn.test/ep.vtt', 10.0);
    }

    /** @return iterable<string, array{int}> */
    public static function transientAnswers(): iterable
    {
        yield 'request timeout' => [Response::HTTP_REQUEST_TIMEOUT];
        yield 'rate limited' => [Response::HTTP_TOO_MANY_REQUESTS];
        yield 'internal error' => [Response::HTTP_INTERNAL_SERVER_ERROR];
        yield 'unavailable' => [Response::HTTP_SERVICE_UNAVAILABLE];
    }

    public function testTheFetchCarriesTheGivenBudgetAndAcceptsAnyType(): void
    {
        /** @var array<string, mixed> $seenOptions */
        $seenOptions = [];
        $http = new MockHttpClient(static function (string $method, string $url, array $options) use (&$seenOptions) {
            $seenOptions = $options;

            return new MockResponse("WEBVTT\n");
        });

        (new GuardedTranscriptSource($this->client($http)))->bodyAt('https://cdn.test/ep.vtt', 10.0);

        self::assertSame(10.0, $seenOptions['timeout']);
        self::assertSame(10.0, $seenOptions['max_duration']);
        self::assertSame(0, $seenOptions['max_redirects']);
        /** @var list<string> $headers */
        $headers = $seenOptions['headers'];
        self::assertContains('Accept: */*', $headers);
    }

    public function testAFileAtTheSizeLimitIsRead(): void
    {
        $body = str_pad("WEBVTT\n", ResponseSizeLimit::TranscriptFile->bytes(), ' ');
        $source = new GuardedTranscriptSource($this->client(new MockHttpClient(new MockResponse($body))));

        self::assertSame($body, $source->bodyAt('https://cdn.test/ep.vtt', 10.0));
    }

    public function testAFileBeyondTheSizeLimitIsUnusable(): void
    {
        $body = str_pad("WEBVTT\n", ResponseSizeLimit::TranscriptFile->bytes() + 1, ' ');
        $source = new GuardedTranscriptSource($this->client(new MockHttpClient(new MockResponse($body))));

        $this->expectException(TranscriptUnusableException::class);
        $source->bodyAt('https://cdn.test/ep.vtt', 10.0);
    }

    public function testTheSizeLimitReadsOneMegabyte(): void
    {
        self::assertSame('1 MB', ResponseSizeLimit::TranscriptFile->label());
    }

    public function testABlockedHostIsUnusable(): void
    {
        $source = new GuardedTranscriptSource($this->client(new MockHttpClient()));

        $this->expectException(TranscriptUnusableException::class);
        $source->bodyAt('http://127.0.0.1/ep.vtt', 10.0);
    }

    public function testAnUnresolvableHostIsUnreachable(): void
    {
        $source = new GuardedTranscriptSource($this->client(new MockHttpClient()));

        $this->expectException(TranscriptUnreachableException::class);
        $source->bodyAt('https://unknown.test/ep.vtt', 10.0);
    }

    public function testANetworkFailureIsUnreachable(): void
    {
        $source = new GuardedTranscriptSource($this->client(new MockHttpClient(
            new MockResponse('', ['error' => 'Connection reset']),
        )));

        $this->expectException(TranscriptUnreachableException::class);
        $source->bodyAt('https://cdn.test/ep.vtt', 10.0);
    }

    private function client(MockHttpClient $http): GuardedApiClient
    {
        $guard = new UrlGuard(new StaticDnsResolver(['cdn.test' => ['93.184.216.34']]), new IpValidator());

        return FetchWiring::guardedApiClient($http, $guard);
    }
}
