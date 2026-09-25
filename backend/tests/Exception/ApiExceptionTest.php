<?php

declare(strict_types=1);

namespace App\Tests\Exception;

use App\Exception\ApiException;
use App\Exception\ValidationException;
use PHPUnit\Framework\TestCase;

final class ApiExceptionTest extends TestCase
{
    public function testValidationExceptionCarriesFieldErrors(): void
    {
        $exception = new ValidationException(['email' => ['Not a valid email address.']]);

        self::assertInstanceOf(ApiException::class, $exception);
        self::assertSame('validation_error', $exception->type);
        self::assertSame(422, $exception->status);
        self::assertSame(['email' => ['Not a valid email address.']], $exception->errors);
    }
}
