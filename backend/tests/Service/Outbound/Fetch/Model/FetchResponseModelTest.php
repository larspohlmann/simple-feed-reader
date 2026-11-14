<?php

declare(strict_types=1);

namespace App\Tests\Service\Fetch\Model;

use App\Service\Fetch\Model\FetchResponseModel;
use PHPUnit\Framework\TestCase;

final class FetchResponseModelTest extends TestCase
{
    public function testFetchedCarriesBodyAndCachingHeaders(): void
    {
        $response = FetchResponseModel::fetched(
            'https://example.com/feed',
            false,
            '<rss/>',
            '"abc"',
            'Mon, 20 Jul 2026 08:30:00 GMT',
        );

        self::assertFalse($response->notModified);
        self::assertSame('https://example.com/feed', $response->finalUrl);
        self::assertFalse($response->permanentRedirect);
        self::assertSame('<rss/>', $response->modifiedBody());
        self::assertSame('"abc"', $response->etag);
        self::assertSame('Mon, 20 Jul 2026 08:30:00 GMT', $response->lastModified);
    }

    public function testNotModifiedEchoesItsCachingHeaders(): void
    {
        $response = FetchResponseModel::notModified('https://example.com/feed', true, '"abc"', null);

        self::assertTrue($response->notModified);
        self::assertTrue($response->permanentRedirect);
        self::assertSame('"abc"', $response->etag);
    }

    public function testANotModifiedResponseHasNoBodyToHandBack(): void
    {
        $response = FetchResponseModel::notModified('https://example.com/feed', false, '"abc"', null);

        $this->expectException(\LogicException::class);

        $response->modifiedBody();
    }
}
