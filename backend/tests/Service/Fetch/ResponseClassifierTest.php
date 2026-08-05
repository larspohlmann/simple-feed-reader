<?php

declare(strict_types=1);

namespace App\Tests\Service\Fetch;

use App\Service\Fetch\Exception\FeedGoneException;
use App\Service\Fetch\Exception\FeedThrottledException;
use App\Service\Fetch\Exception\FeedUnreachableException;
use App\Service\Fetch\Exception\ResponseTooLargeException;
use App\Service\Fetch\FetchAttempt;
use App\Service\Fetch\FetchTicket;
use App\Service\Fetch\HeaderDecision;
use App\Service\Fetch\ResponseClassifier;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\ResponseInterface;

final class ResponseClassifierTest extends TestCase
{
    private function attempt(string $url = 'https://example.com/feed'): FetchAttempt
    {
        return FetchAttempt::start(1, new FetchTicket($url, '"v1"', 'Mon, 20 Jul 2026 08:30:00 GMT'));
    }

    private function classifier(string $now = '2026-08-05 12:00:00'): ResponseClassifier
    {
        return new ResponseClassifier(new MockClock($now));
    }

    private function respond(MockResponse $mock): ResponseInterface
    {
        return (new MockHttpClient($mock))->request('GET', 'https://example.com/feed');
    }

    public function testATwoHundredAwaitsTheBody(): void
    {
        $verdict = $this->classifier()->fromHeaders(
            $this->respond(new MockResponse('<rss/>', ['http_code' => 200])),
            $this->attempt(),
        );

        self::assertSame(HeaderDecision::AwaitBody, $verdict->decision);
    }

    public function testAThreeOhOneRedirectsAndIsPermanent(): void
    {
        $verdict = $this->classifier()->fromHeaders(
            $this->respond(new MockResponse('', [
                'http_code' => 301,
                'response_headers' => ['location' => 'https://example.com/moved'],
            ])),
            $this->attempt(),
        );

        self::assertSame(HeaderDecision::Redirect, $verdict->decision);
        self::assertSame('https://example.com/moved', $verdict->redirectUrl);
        self::assertTrue($verdict->permanent);
    }

    public function testAThreeOhTwoRedirectsButIsNotPermanent(): void
    {
        $verdict = $this->classifier()->fromHeaders(
            $this->respond(new MockResponse('', [
                'http_code' => 302,
                'response_headers' => ['location' => '/relative'],
            ])),
            $this->attempt(),
        );

        self::assertSame(HeaderDecision::Redirect, $verdict->decision);
        self::assertSame('https://example.com/relative', $verdict->redirectUrl);
        self::assertFalse($verdict->permanent);
    }

    /** @return iterable<string, array{int}> */
    public static function otherTemporaryRedirectCodes(): iterable
    {
        yield '303' => [303];
        yield '307' => [307];
    }

    #[DataProvider('otherTemporaryRedirectCodes')]
    public function testOtherRedirectCodesAreNotPermanent(int $statusCode): void
    {
        $verdict = $this->classifier()->fromHeaders(
            $this->respond(new MockResponse('', [
                'http_code' => $statusCode,
                'response_headers' => ['location' => 'https://example.com/moved'],
            ])),
            $this->attempt(),
        );

        self::assertSame(HeaderDecision::Redirect, $verdict->decision);
        self::assertFalse($verdict->permanent);
    }

    public function testARedirectWithoutALocationIsUnreachable(): void
    {
        $this->expectException(FeedUnreachableException::class);

        $this->classifier()->fromHeaders(
            $this->respond(new MockResponse('', ['http_code' => 302])),
            $this->attempt(),
        );
    }

    public function testAThreeOhFourIsTerminalAndEchoesTheCachingHeaders(): void
    {
        $verdict = $this->classifier()->fromHeaders(
            $this->respond(new MockResponse('', ['http_code' => 304])),
            $this->attempt(),
        );

        self::assertSame(HeaderDecision::Terminal, $verdict->decision);
        $response = $verdict->response;
        self::assertNotNull($response);
        self::assertTrue($response->notModified);
        self::assertSame('"v1"', $response->etag);
        self::assertSame('Mon, 20 Jul 2026 08:30:00 GMT', $response->lastModified);
    }

    public function testAFourTenIsGone(): void
    {
        $this->expectException(FeedGoneException::class);

        $this->classifier()->fromHeaders(
            $this->respond(new MockResponse('', ['http_code' => 410])),
            $this->attempt(),
        );
    }

    /**
     * @return iterable<string, array{string|null, int|null}>
     */
    public static function retryAfterHeaders(): iterable
    {
        yield 'a delay in seconds' => ['90', 90];
        // RFC 9110 allows the date the door reopens instead of a delay.
        yield 'the date it reopens' => ['Wed, 05 Aug 2026 12:02:30 GMT', 150];
        yield 'a date already past' => ['Wed, 05 Aug 2026 11:59:00 GMT', null];
        yield 'no header at all' => [null, null];
        yield 'something unparseable' => ['soon-ish', null];
    }

    #[DataProvider('retryAfterHeaders')]
    public function testAFourTwentyNineIsThrottledAndReadsTheDelayItWasGiven(
        ?string $retryAfter,
        ?int $expectedSeconds,
    ): void {
        $headers = null === $retryAfter ? [] : ['response_headers' => ['retry-after' => $retryAfter]];

        try {
            $this->classifier()->fromHeaders(
                $this->respond(new MockResponse('', ['http_code' => 429] + $headers)),
                $this->attempt(),
            );
            self::fail('Expected a FeedThrottledException.');
        } catch (FeedThrottledException $e) {
            self::assertSame($expectedSeconds, $e->retryAfterSeconds);
        }
    }

    public function testAFiveHundredIsUnreachable(): void
    {
        $this->expectException(FeedUnreachableException::class);

        $this->classifier()->fromHeaders(
            $this->respond(new MockResponse('', ['http_code' => 500])),
            $this->attempt(),
        );
    }

    public function testTheBodyPhaseBuildsAFetchedResponse(): void
    {
        $response = $this->respond(new MockResponse('<rss/>', [
            'http_code' => 200,
            'response_headers' => ['etag' => '"v2"', 'last-modified' => 'Tue, 21 Jul 2026 08:30:00 GMT'],
        ]));

        $fetched = $this->classifier()->fromBody($response, $this->attempt());

        self::assertFalse($fetched->notModified);
        self::assertSame('<rss/>', $fetched->body);
        self::assertSame('"v2"', $fetched->etag);
        self::assertSame('Tue, 21 Jul 2026 08:30:00 GMT', $fetched->lastModified);
        self::assertSame('https://example.com/feed', $fetched->finalUrl);
    }

    public function testABodyOverTheSizeCapIsRejected(): void
    {
        $this->expectException(ResponseTooLargeException::class);

        $this->classifier()->fromBody(
            $this->respond(new MockResponse(str_repeat('x', 5_000_001), ['http_code' => 200])),
            $this->attempt(),
        );
    }
}
