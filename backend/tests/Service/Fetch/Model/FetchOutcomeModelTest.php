<?php

declare(strict_types=1);

namespace App\Tests\Service\Fetch\Model;

use App\Service\Fetch\Exception\FeedUnreachableException;
use App\Service\Fetch\Model\ApiResponseModel;
use App\Service\Fetch\Model\FetchOutcomeModel;
use App\Service\Fetch\Model\FetchResponseModel;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Response;

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

    public function testAnAnsweredOutcomeCarriesItsAnswerAndNoFailure(): void
    {
        $answer = new ApiResponseModel(Response::HTTP_UNAUTHORIZED, '{"error":"InvalidToken"}');

        $outcome = FetchOutcomeModel::answered($answer);

        self::assertSame($answer, $outcome->answerOrThrow());
        self::assertNull($outcome->failure());
        self::assertFalse($outcome->isWithheld());
    }

    public function testAFailedOutcomeRethrowsInsteadOfAnswering(): void
    {
        $failure = new FeedUnreachableException('https://example.com/api: timed out');

        $this->expectExceptionObject($failure);

        FetchOutcomeModel::failed($failure)->answerOrThrow();
    }

    public function testAFailedOutcomeIsNotWithheld(): void
    {
        self::assertFalse(FetchOutcomeModel::failed(new FeedUnreachableException('x: timed out'))->isWithheld());
    }

    public function testAWithheldOutcomeIsNeitherAnsweredNorFailed(): void
    {
        $outcome = FetchOutcomeModel::withheld();

        self::assertTrue($outcome->isWithheld());
        self::assertNull($outcome->failure());
        $this->expectException(\LogicException::class);
        $outcome->answerOrThrow();
    }

    public function testAFeedOutcomeCarriesNoApiAnswer(): void
    {
        $outcome = FetchOutcomeModel::succeeded(
            FetchResponseModel::notModified('https://example.com/feed', false, null, null),
        );

        self::assertFalse($outcome->isWithheld());
        $this->expectException(\LogicException::class);
        $outcome->answerOrThrow();
    }

    public function testAnApiOutcomeCarriesNoFeedResponse(): void
    {
        $this->expectException(\LogicException::class);

        FetchOutcomeModel::answered(new ApiResponseModel(Response::HTTP_OK, '{}'))->responseOrThrow();
    }
}
