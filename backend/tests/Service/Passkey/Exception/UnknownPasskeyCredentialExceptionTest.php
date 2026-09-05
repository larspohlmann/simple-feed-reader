<?php

declare(strict_types=1);

namespace App\Tests\Service\Passkey\Exception;

use App\Service\Passkey\Exception\UnknownPasskeyCredentialException;
use PHPUnit\Framework\TestCase;

/**
 * The one passkey login failure whose type reaches the client (#727) — the
 * string the frontend branches on before it signals the browser.
 */
final class UnknownPasskeyCredentialExceptionTest extends TestCase
{
    public function testItIsA401WithTheTypeTheFrontendBranchesOn(): void
    {
        $exception = new UnknownPasskeyCredentialException();

        self::assertSame('unknown_passkey_credential', $exception->type);
        self::assertSame(401, $exception->status);
        self::assertSame('Unknown passkey', $exception->title);
        self::assertSame('This passkey is not registered here.', $exception->detail);
    }
}
