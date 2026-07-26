<?php

declare(strict_types=1);

namespace App\Tests\Service\Fetch;

use App\Service\Fetch\Exception\FeedUnreachableException;
use App\Service\Fetch\FetchOutcome;
use App\Service\Fetch\FetchResponse;
use PHPUnit\Framework\TestCase;

final class FetchOutcomeTest extends TestCase
{
    public function testSucceededCarriesTheResponse(): void
    {
        $response = FetchResponse::notModified('https://example.com/feed', false, null, null);

        $outcome = FetchOutcome::succeeded($response);

        self::assertSame($response, $outcome->responseOrThrow());
    }

    public function testFailedRethrowsTheOriginalException(): void
    {
        $failure = new FeedUnreachableException('https://example.com/feed: HTTP 500');

        $outcome = FetchOutcome::failed($failure);

        self::assertSame($failure, $outcome->failure());
        $this->expectExceptionObject($failure);
        $outcome->responseOrThrow();
    }

    public function testASucceededOutcomeHasNoFailure(): void
    {
        $outcome = FetchOutcome::succeeded(
            FetchResponse::notModified('https://example.com/feed', false, null, null),
        );

        self::assertNull($outcome->failure());
    }
}
