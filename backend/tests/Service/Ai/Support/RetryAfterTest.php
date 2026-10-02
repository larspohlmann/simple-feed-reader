<?php

declare(strict_types=1);

namespace App\Tests\Service\Ai\Support;

use App\Service\Ai\Support\RetryAfter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class RetryAfterTest extends TestCase
{
    /** @return iterable<string, array{array<string, string>, ?int}> */
    public static function headers(): iterable
    {
        yield 'a seconds count' => [['retry-after' => '17'], 17];
        yield 'an HTTP date' => [['retry-after' => 'Wed, 21 Oct 2026 07:28:00 GMT'], null];
        yield 'a negative count' => [['retry-after' => '-5'], null];
        yield 'no header' => [[], null];
    }

    /** @param array<string, string> $headers */
    #[DataProvider('headers')]
    public function testOnlyAnIntegerSecondsCountNamesAWait(array $headers, ?int $seconds): void
    {
        $response = (new MockHttpClient(new MockResponse('', ['http_code' => 429, 'response_headers' => $headers])))
            ->request('POST', 'https://api.example.test/v1/systemone');

        self::assertSame($seconds, RetryAfter::secondsIn($response));
    }
}
