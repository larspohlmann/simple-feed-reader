<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\Exception;

use App\Service\Fetch\Model\ApiResponseModel;
use App\Service\Social\Exception\SocialNetworkThrottledException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Response;

final class SocialNetworkThrottledExceptionTest extends TestCase
{
    public function testATooManyRequestsAnswerThrowsWithItsRetryAfter(): void
    {
        $answer = new ApiResponseModel(Response::HTTP_TOO_MANY_REQUESTS, '', ['retry-after' => ['90']]);

        try {
            SocialNetworkThrottledException::throwIfThrottled($answer, 'The network asked for fewer requests.');
            self::fail('A 429 must throw.');
        } catch (SocialNetworkThrottledException $exception) {
            self::assertSame('The network asked for fewer requests.', $exception->getMessage());
            self::assertSame(90, $exception->retryAfterSeconds);
        }
    }

    public function testAnyOtherAnswerPasses(): void
    {
        $this->expectNotToPerformAssertions();

        SocialNetworkThrottledException::throwIfThrottled(
            new ApiResponseModel(Response::HTTP_SERVICE_UNAVAILABLE, '', ['retry-after' => ['90']]),
            'The network asked for fewer requests.',
        );
    }
}
