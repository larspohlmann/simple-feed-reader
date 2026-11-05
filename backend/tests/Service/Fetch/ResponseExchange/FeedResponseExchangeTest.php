<?php

declare(strict_types=1);

namespace App\Tests\Service\Fetch\ResponseExchange;

use App\Service\Fetch\Exception\FeedGoneException;
use App\Service\Fetch\Exception\FeedThrottledException;
use App\Service\Fetch\Exception\FeedUnreachableException;
use App\Service\Fetch\Exception\ResponseTooLargeException;
use App\Service\Fetch\Model\FetchAttemptModel;
use App\Service\Fetch\Model\ResponseSizeLimit;
use App\Service\Fetch\ResponseExchange\FeedResponseExchange;
use App\Tests\Support\FeedTickets;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\ResponseInterface;

final class FeedResponseExchangeTest extends TestCase
{
    private function attempt(string $url = 'https://example.com/feed'): FetchAttemptModel
    {
        return FetchAttemptModel::start(1, FeedTickets::of($url, '"v1"', 'Mon, 20 Jul 2026 08:30:00 GMT'));
    }

    private function exchange(string $now = '2026-08-05 12:00:00'): FeedResponseExchange
    {
        return new FeedResponseExchange(new MockClock($now));
    }

    private function respond(MockResponse $mock): ResponseInterface
    {
        return (new MockHttpClient($mock))->request('GET', 'https://example.com/feed');
    }

    public function testATwoHundredAwaitsTheBody(): void
    {
        $step = $this->exchange()->onHeaders(
            $this->respond(new MockResponse('<rss/>', ['http_code' => 200])),
            $this->attempt(),
        );

        self::assertTrue($step->isAwaitingBody());
    }

    public function testAThreeOhOneRedirectsAndIsPermanent(): void
    {
        $step = $this->exchange()->onHeaders(
            $this->respond(new MockResponse('', [
                'http_code' => 301,
                'response_headers' => ['location' => 'https://example.com/moved'],
            ])),
            $this->attempt(),
        );

        self::assertNotNull($step->nextAttempt);
        self::assertSame('https://example.com/moved', $step->nextAttempt->url);
        self::assertTrue($step->nextAttempt->permanentRedirect);
    }

    public function testAThreeOhTwoRedirectsButIsNotPermanent(): void
    {
        $step = $this->exchange()->onHeaders(
            $this->respond(new MockResponse('', [
                'http_code' => 302,
                'response_headers' => ['location' => '/relative'],
            ])),
            $this->attempt(),
        );

        self::assertNotNull($step->nextAttempt);
        self::assertSame('https://example.com/relative', $step->nextAttempt->url);
        self::assertFalse($step->nextAttempt->permanentRedirect);
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
        $step = $this->exchange()->onHeaders(
            $this->respond(new MockResponse('', [
                'http_code' => $statusCode,
                'response_headers' => ['location' => 'https://example.com/moved'],
            ])),
            $this->attempt(),
        );

        self::assertNotNull($step->nextAttempt);
        self::assertFalse($step->nextAttempt->permanentRedirect);
    }

    public function testARedirectWithoutALocationIsUnreachable(): void
    {
        $this->expectException(FeedUnreachableException::class);

        $this->exchange()->onHeaders(
            $this->respond(new MockResponse('', ['http_code' => 302])),
            $this->attempt(),
        );
    }

    public function testAThreeOhFourIsTerminalAndEchoesTheCachingHeaders(): void
    {
        $step = $this->exchange()->onHeaders(
            $this->respond(new MockResponse('', ['http_code' => 304])),
            $this->attempt(),
        );

        self::assertNotNull($step->outcome);
        $response = $step->outcome->responseOrThrow();
        self::assertTrue($response->notModified);
        self::assertSame('"v1"', $response->etag);
        self::assertSame('Mon, 20 Jul 2026 08:30:00 GMT', $response->lastModified);
    }

    public function testAThreeOhFourToAnUnconditionalRequestIsAnEmptyFetch(): void
    {
        $step = $this->exchange()->onHeaders(
            $this->respond(new MockResponse('', ['http_code' => 304])),
            FetchAttemptModel::start(1, FeedTickets::of('https://example.com/feed')),
        );

        self::assertNotNull($step->outcome);
        $response = $step->outcome->responseOrThrow();
        self::assertFalse($response->notModified);
        self::assertSame('', $response->modifiedBody());
    }

    /** @return iterable<string, array{?string, ?string}> */
    public static function singleCachingHeaders(): iterable
    {
        yield 'an ETag only' => ['"v1"', null];
        yield 'a Last-Modified only' => [null, 'Mon, 20 Jul 2026 08:30:00 GMT'];
    }

    #[DataProvider('singleCachingHeaders')]
    public function testAThreeOhFourToATicketCarryingOnlyOneCachingHeaderIsStillNotModified(
        ?string $etag,
        ?string $lastModified,
    ): void {
        $step = $this->exchange()->onHeaders(
            $this->respond(new MockResponse('', ['http_code' => 304])),
            FetchAttemptModel::start(1, FeedTickets::of('https://example.com/feed', $etag, $lastModified)),
        );

        self::assertNotNull($step->outcome);
        $response = $step->outcome->responseOrThrow();
        self::assertTrue($response->notModified);
    }

    public function testAFourTenIsGone(): void
    {
        $this->expectException(FeedGoneException::class);

        $this->exchange()->onHeaders(
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
        yield 'an absurd delay, clamped later' => ['9999999999', 9999999999];
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
            $this->exchange()->onHeaders(
                $this->respond(new MockResponse('', ['http_code' => 429] + $headers)),
                $this->attempt(),
            );
            self::fail('Expected a FeedThrottledException.');
        } catch (FeedThrottledException $exception) {
            self::assertSame($expectedSeconds, $exception->retryAfterSeconds);
        }
    }

    public function testAThreeHundredIsUnreachableRatherThanFollowed(): void
    {
        $this->expectException(FeedUnreachableException::class);
        $this->expectExceptionMessageMatches('#^https://example\.com/feed: HTTP 300$#');

        $this->exchange()->onHeaders(
            $this->respond(new MockResponse('', [
                'http_code' => 300,
                'response_headers' => ['location' => 'https://example.com/moved'],
            ])),
            $this->attempt(),
        );
    }

    public function testAFiveHundredIsUnreachable(): void
    {
        $this->expectException(FeedUnreachableException::class);
        $this->expectExceptionMessageMatches('#^https://example\.com/feed: HTTP 500$#');

        $this->exchange()->onHeaders(
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

        $fetched = $this->exchange()->onBody($response, $this->attempt())->responseOrThrow();

        self::assertFalse($fetched->notModified);
        self::assertSame('<rss/>', $fetched->modifiedBody());
        self::assertSame('"v2"', $fetched->etag);
        self::assertSame('Tue, 21 Jul 2026 08:30:00 GMT', $fetched->lastModified);
        self::assertSame('https://example.com/feed', $fetched->finalUrl);
    }

    public function testABodyOverTheSizeCapIsRejected(): void
    {
        $oversized = new MockResponse(str_repeat('x', ResponseSizeLimit::Feed->bytes() + 1), ['http_code' => 200]);

        $this->expectException(ResponseTooLargeException::class);

        $this->exchange()->onBody(
            $this->respond($oversized),
            $this->attempt(),
        );
    }

    public function testARedirectPastTheCapIsUnreachable(): void
    {
        $attempt = $this->attempt();
        for ($hop = 0; $hop < FetchAttemptModel::MAX_REDIRECTS; ++$hop) {
            $attempt = $attempt->followedTo('https://example.com/hop-' . $hop, false);
        }

        $this->expectException(FeedUnreachableException::class);
        $this->expectExceptionMessage('https://example.com/feed: more than 5 redirects');

        $this->exchange()->onHeaders(
            $this->respond(new MockResponse('', [
                'http_code' => 302,
                'response_headers' => ['location' => 'https://example.com/moved'],
            ])),
            $attempt,
        );
    }
}
