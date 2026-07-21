<?php

declare(strict_types=1);

namespace App\Tests\Exception;

use App\Exception\AccountNotActiveException;
use App\Exception\ApiException;
use App\Exception\InvalidCredentialsException;
use App\Exception\InvalidTokenException;
use App\Exception\RateLimitedException;
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

    public function testInvalidCredentialsIs401(): void
    {
        $exception = new InvalidCredentialsException();

        self::assertSame('invalid_credentials', $exception->type);
        self::assertSame(401, $exception->status);
    }

    public function testAccountNotActiveIs403AndNamesTheStatus(): void
    {
        $exception = new AccountNotActiveException('pending_approval');

        self::assertSame('account_not_active', $exception->type);
        self::assertSame(403, $exception->status);
        self::assertSame('pending_approval', $exception->accountStatus);
    }

    public function testInvalidTokenIs400(): void
    {
        self::assertSame(400, (new InvalidTokenException())->status);
        self::assertSame('invalid_token', (new InvalidTokenException())->type);
    }

    public function testRateLimitedIs429AndCarriesRetryAfter(): void
    {
        $exception = new RateLimitedException(42);

        self::assertSame('rate_limited', $exception->type);
        self::assertSame(429, $exception->status);
        self::assertSame(42, $exception->retryAfterSeconds);
    }
}
