<?php

declare(strict_types=1);

namespace App\Tests\Service\Chapter\ChapterFileSource;

use App\Service\Chapter\ChapterFileSource\GuardedChapterFileSource;
use App\Service\Chapter\Exception\ChapterFileUnreachableException;
use App\Service\Fetch\GuardedApiClient;
use App\Service\Fetch\IpValidator;
use App\Service\Fetch\Model\ResponseSizeLimit;
use App\Service\Fetch\UrlGuard;
use App\Tests\Support\FetchWiring;
use App\Tests\Support\StaticDnsResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Response;

final class GuardedChapterFileSourceTest extends TestCase
{
    public function testReadsTheChaptersOfAnAnsweredFile(): void
    {
        $source = new GuardedChapterFileSource($this->client(new MockHttpClient(new MockResponse(
            '{"chapters":[{"startTime":0,"title":"A"},{"startTime":60,"title":"B"}]}',
            ['http_code' => Response::HTTP_OK],
        ))));

        self::assertCount(2, $source->chaptersAt('https://cdn.test/ch.json'));
    }

    #[DataProvider('answersWithoutChapters')]
    public function testAnErrorAnswerOrARedirectHasNoChapters(int $status): void
    {
        $source = new GuardedChapterFileSource($this->client(new MockHttpClient(new MockResponse('', [
            'http_code' => $status,
            'response_headers' => ['Location' => 'https://elsewhere.test/ch.json'],
        ]))));

        self::assertSame([], $source->chaptersAt('https://cdn.test/ch.json'));
    }

    /** @return iterable<string, array{int}> */
    public static function answersWithoutChapters(): iterable
    {
        yield 'not found' => [Response::HTTP_NOT_FOUND];
        yield 'last client error' => [Response::HTTP_INTERNAL_SERVER_ERROR - 1];
        yield 'redirect' => [Response::HTTP_MOVED_PERMANENTLY];
    }

    #[DataProvider('serverErrors')]
    public function testAServerErrorIsUnreachable(int $status): void
    {
        $source = new GuardedChapterFileSource($this->client(new MockHttpClient(
            new MockResponse('', ['http_code' => $status]),
        )));

        $this->expectException(ChapterFileUnreachableException::class);
        $source->chaptersAt('https://cdn.test/ch.json');
    }

    /** @return iterable<string, array{int}> */
    public static function serverErrors(): iterable
    {
        yield 'internal error' => [Response::HTTP_INTERNAL_SERVER_ERROR];
        yield 'unavailable' => [Response::HTTP_SERVICE_UNAVAILABLE];
    }

    public function testTheFetchCarriesTheShortChapterBudget(): void
    {
        $seenOptions = [];
        $http = new MockHttpClient(static function (string $method, string $url, array $options) use (&$seenOptions) {
            $seenOptions = $options;

            return new MockResponse('{"chapters":[]}');
        });

        (new GuardedChapterFileSource($this->client($http)))->chaptersAt('https://cdn.test/ch.json');

        self::assertSame(3.0, $seenOptions['timeout']);
        self::assertSame(3.0, $seenOptions['max_duration']);
        self::assertSame(0, $seenOptions['max_redirects']);
    }

    public function testAFileAtTheChapterSizeLimitIsRead(): void
    {
        $source = new GuardedChapterFileSource($this->client(new MockHttpClient(
            new MockResponse(self::chaptersPaddedTo(ResponseSizeLimit::ChapterFile->bytes())),
        )));

        self::assertCount(2, $source->chaptersAt('https://cdn.test/ch.json'));
    }

    public function testAFileBeyondTheChapterSizeLimitHasNoChapters(): void
    {
        $source = new GuardedChapterFileSource($this->client(new MockHttpClient(
            new MockResponse(self::chaptersPaddedTo(ResponseSizeLimit::ChapterFile->bytes() + 1)),
        )));

        self::assertSame([], $source->chaptersAt('https://cdn.test/ch.json'));
    }

    private static function chaptersPaddedTo(int $bytes): string
    {
        return str_pad('{"chapters":[{"startTime":0,"title":"A"},{"startTime":60,"title":"B"}]}', $bytes, ' ');
    }

    public function testABlockedHostHasNoChapters(): void
    {
        $source = new GuardedChapterFileSource($this->client(new MockHttpClient()));

        self::assertSame([], $source->chaptersAt('http://127.0.0.1/ch.json'));
    }

    public function testANetworkFailureIsUnreachable(): void
    {
        $source = new GuardedChapterFileSource($this->client(new MockHttpClient(
            new MockResponse('', ['error' => 'Connection reset']),
        )));

        $this->expectException(ChapterFileUnreachableException::class);
        $source->chaptersAt('https://cdn.test/ch.json');
    }

    private function client(MockHttpClient $http): GuardedApiClient
    {
        $guard = new UrlGuard(new StaticDnsResolver(['cdn.test' => ['93.184.216.34']]), new IpValidator());

        return FetchWiring::guardedApiClient($http, $guard);
    }
}
