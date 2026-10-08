<?php

declare(strict_types=1);

namespace App\Tests\Service\Fetch\Exception;

use App\Service\Fetch\Exception\ResponseTooLargeException;
use App\Service\Fetch\Model\ResponseSizeLimit;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ResponseTooLargeExceptionTest extends TestCase
{
    /** @return iterable<string, array{ResponseSizeLimit}> */
    public static function limits(): iterable
    {
        foreach (ResponseSizeLimit::cases() as $limit) {
            yield $limit->name => [$limit];
        }
    }

    #[DataProvider('limits')]
    public function testAResponseExactlyAtTheLimitPasses(ResponseSizeLimit $limit): void
    {
        $this->expectNotToPerformAssertions();

        ResponseTooLargeException::throwIfExceeded($limit, $limit->value);
    }

    #[DataProvider('limits')]
    public function testOneByteOverTheLimitIsRefused(ResponseSizeLimit $limit): void
    {
        $this->expectException(ResponseTooLargeException::class);

        ResponseTooLargeException::throwIfExceeded($limit, $limit->value + 1);
    }

    public function testTheMessageNamesTheUrlAndTheLimitInMegabytes(): void
    {
        $this->expectExceptionMessage('https://feeds.example/rss: the response is larger than the 20 MB limit');

        ResponseTooLargeException::throwIfExceeded(ResponseSizeLimit::Feed, 20_000_001, 'https://feeds.example/rss');
    }

    public function testWithoutAUrlTheMessageIsTheReasonAlone(): void
    {
        $this->expectExceptionMessageMatches('/^the response is larger than the 5 MB limit$/');

        ResponseTooLargeException::throwIfExceeded(ResponseSizeLimit::Download, 5_000_001);
    }
}
