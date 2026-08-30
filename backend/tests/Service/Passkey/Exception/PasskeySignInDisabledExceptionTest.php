<?php

declare(strict_types=1);

namespace App\Tests\Service\Passkey\Exception;

use App\Service\Passkey\Exception\PasskeySignInDisabledException;
use PHPUnit\Framework\TestCase;

final class PasskeySignInDisabledExceptionTest extends TestCase
{
    public function testItIsA403WithAFixedTitleAndDetail(): void
    {
        $exception = new PasskeySignInDisabledException();

        self::assertSame('passkey_sign_in_disabled', $exception->type);
        self::assertSame(403, $exception->status);
        self::assertSame('Passkey sign-in is disabled', $exception->title);
        self::assertSame('This instance has turned off passkey sign-in.', $exception->detail);
    }
}
