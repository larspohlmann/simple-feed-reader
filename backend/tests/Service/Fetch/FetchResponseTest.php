<?php

declare(strict_types=1);

namespace App\Tests\Service\Fetch;

use App\Service\Fetch\FetchResponse;
use PHPUnit\Framework\TestCase;

final class FetchResponseTest extends TestCase
{
    public function testFetchedCarriesBodyAndCachingHeaders(): void
    {
        $response = FetchResponse::fetched(
            'https://example.com/feed',
            false,
            '<rss/>',
            '"abc"',
            'Mon, 20 Jul 2026 08:30:00 GMT',
        );

        self::assertFalse($response->notModified);
        self::assertSame('https://example.com/feed', $response->finalUrl);
        self::assertFalse($response->permanentRedirect);
        self::assertSame('<rss/>', $response->body);
        self::assertSame('"abc"', $response->etag);
        self::assertSame('Mon, 20 Jul 2026 08:30:00 GMT', $response->lastModified);
    }

    public function testNotModifiedHasNoBody(): void
    {
        $response = FetchResponse::notModified('https://example.com/feed', true, '"abc"', null);

        self::assertTrue($response->notModified);
        self::assertTrue($response->permanentRedirect);
        self::assertNull($response->body);
        self::assertSame('"abc"', $response->etag);
    }
}
