<?php

declare(strict_types=1);

namespace App\Tests\Service\Fetch\Model;

use App\Service\Fetch\Exception\FeedUnreachableException;
use App\Service\Fetch\Model\FetchOutcomeModel;
use App\Service\Fetch\Model\FetchResponseModel;
use PHPUnit\Framework\TestCase;

final class FetchOutcomeModelTest extends TestCase
{
    public function testSucceededCarriesTheResponse(): void
    {
        $response = FetchResponseModel::notModified('https://example.com/feed', false, null, null);

        $outcome = FetchOutcomeModel::succeeded($response);

        self::assertSame($response, $outcome->responseOrThrow());
    }

    public function testFailedRethrowsTheOriginalException(): void
    {
        $failure = new FeedUnreachableException('https://example.com/feed: HTTP 500');

        $outcome = FetchOutcomeModel::failed($failure);

        self::assertSame($failure, $outcome->failure());
        $this->expectExceptionObject($failure);
        $outcome->responseOrThrow();
    }

    public function testASucceededOutcomeHasNoFailure(): void
    {
        $outcome = FetchOutcomeModel::succeeded(
            FetchResponseModel::notModified('https://example.com/feed', false, null, null),
        );

        self::assertNull($outcome->failure());
    }
}
