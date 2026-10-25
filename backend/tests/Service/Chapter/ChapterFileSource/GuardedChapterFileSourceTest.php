<?php

declare(strict_types=1);

namespace App\Tests\Service\Chapter\ChapterFileSource;

use App\Service\Chapter\ChapterFileSource\GuardedChapterFileSource;
use App\Service\Chapter\Exception\ChapterFileUnreachableException;
use App\Service\Fetch\GuardedApiClient;
use App\Service\Fetch\IpValidator;
use App\Service\Fetch\UrlGuard;
use App\Tests\Support\FetchWiring;
use App\Tests\Support\StaticDnsResolver;
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

    public function testAnErrorAnswerOrARedirectHasNoChapters(): void
    {
        foreach ([Response::HTTP_NOT_FOUND, Response::HTTP_MOVED_PERMANENTLY] as $status) {
            $source = new GuardedChapterFileSource($this->client(new MockHttpClient(new MockResponse('', [
                'http_code' => $status,
                'response_headers' => ['Location' => 'https://elsewhere.test/ch.json'],
            ]))));

            self::assertSame([], $source->chaptersAt('https://cdn.test/ch.json'));
        }
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
