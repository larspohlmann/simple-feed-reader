<?php

declare(strict_types=1);

namespace App\Tests\Service\Passkey\Exception;

use App\Service\Passkey\Exception\PasskeyNotFoundException;
use PHPUnit\Framework\TestCase;

final class PasskeyNotFoundExceptionTest extends TestCase
{
    public function testItIsA404WithAFixedTypeAndTitle(): void
    {
        $exception = new PasskeyNotFoundException();

        self::assertSame('passkey_not_found', $exception->type);
        self::assertSame(404, $exception->status);
        self::assertSame('No such passkey', $exception->title);
        self::assertNull($exception->detail);
    }
}
