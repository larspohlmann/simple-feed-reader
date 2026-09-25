<?php

declare(strict_types=1);

namespace App\Tests\Exception;

use App\Exception\ValidationException;
use PHPUnit\Framework\TestCase;

final class ValidationExceptionTest extends TestCase
{
    public function testItCarriesTheFieldErrorsAndAReadableMessage(): void
    {
        $exception = new ValidationException(['email' => ['Required.']]);

        self::assertSame(['email' => ['Required.']], $exception->errors);
        self::assertSame('One or more fields are invalid.', $exception->getMessage());
    }
}
