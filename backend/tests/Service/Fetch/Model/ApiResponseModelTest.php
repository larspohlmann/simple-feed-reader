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
}
