<?php

declare(strict_types=1);

namespace App\Tests\Service\Fetch\Model;

use App\Service\Fetch\Model\ApiResponseModel;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Response;

final class ApiResponseModelTest extends TestCase
{
    /** @return iterable<string, array{int, bool}> */
    public static function statuses(): iterable
    {
        yield 'below 2xx' => [Response::HTTP_CONTINUE, false];
        yield 'first 2xx' => [Response::HTTP_OK, true];
        yield 'last 2xx' => [Response::HTTP_MULTIPLE_CHOICES - 1, true];
        yield 'first 3xx' => [Response::HTTP_MULTIPLE_CHOICES, false];
        yield 'error' => [Response::HTTP_UNAUTHORIZED, false];
    }

    #[DataProvider('statuses')]
    public function testOnlyA2xxStatusIsSuccessful(int $status, bool $expected): void
    {
        self::assertSame($expected, (new ApiResponseModel($status, ''))->isSuccessful());
    }

    /** @return iterable<string, array{int, bool}> */
    public static function transientStatuses(): iterable
    {
        yield 'last 4xx' => [Response::HTTP_INTERNAL_SERVER_ERROR - 1, false];
        yield 'not found' => [Response::HTTP_NOT_FOUND, false];
        yield 'just below request timeout' => [Response::HTTP_REQUEST_TIMEOUT - 1, false];
        yield 'request timeout' => [Response::HTTP_REQUEST_TIMEOUT, true];
        yield 'just above request timeout' => [Response::HTTP_REQUEST_TIMEOUT + 1, false];
        yield 'just below too many requests' => [Response::HTTP_TOO_MANY_REQUESTS - 1, false];
        yield 'too many requests' => [Response::HTTP_TOO_MANY_REQUESTS, true];
        yield 'just above too many requests' => [Response::HTTP_TOO_MANY_REQUESTS + 1, false];
        yield 'first 5xx' => [Response::HTTP_INTERNAL_SERVER_ERROR, true];
        yield 'unavailable' => [Response::HTTP_SERVICE_UNAVAILABLE, true];
    }

    #[DataProvider('transientStatuses')]
    public function testOnlyA5xxATimeoutOrARateLimitIsTransient(int $status, bool $expected): void
    {
        self::assertSame($expected, (new ApiResponseModel($status, ''))->isTransientFailure());
    }

    public function testAHeaderReadsItsFirstValueWhateverTheCaseOfItsName(): void
    {
        $response = new ApiResponseModel(
            Response::HTTP_OK,
            '',
            ['link' => ['<https://a.example.test/2>; rel="next"', 'second']],
        );

        self::assertSame('<https://a.example.test/2>; rel="next"', $response->header('Link'));
        self::assertNull($response->header('X-Absent'));
    }
}
